<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Printers;

use Rawand\KurdishEscpos\Exceptions\PrinterException;
use Throwable;

/**
 * Sends receipts to several printers at the same time.
 *
 * A receipt printer accepts data only as fast as it prints, so sending to
 * printers one after another can take several seconds per printer. Network
 * printers therefore receive their data in parallel over non-blocking
 * sockets. Other printers are written to one after another.
 *
 * @internal
 */
final class ParallelSender
{
    private const CHUNK = 65536;

    /**
     * @param  array<string, array{Printer, string}>  $jobs  name => [printer, bytes]
     * @return array<string, Throwable|null> name => null on success, or the error
     */
    public static function send(array $jobs): array
    {
        $results = array_fill_keys(array_keys($jobs), null);
        $pending = [];
        foreach ($jobs as $name => [$printer, $bytes]) {
            if (! $printer instanceof NetworkPrinter) {
                continue;
            }
            $address = $printer->address();
            $socket = @stream_socket_client($address, $errno, $error, $printer->timeout(), STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if ($socket === false) {
                $results[$name] = new PrinterException("Could not connect to the printer at {$address}: {$error} ({$errno}).");

                continue;
            }
            stream_set_blocking($socket, false);
            $pending[$name] = [
                'socket' => $socket, 'bytes' => $bytes, 'sent' => 0, 'address' => $address,
                'timeout' => $printer->timeout(), 'deadline' => microtime(true) + $printer->timeout(), 'connected' => false,
            ];
        }

        while ($pending !== []) {
            $write = array_column($pending, 'socket');
            $read = $except = null;
            if (@stream_select($read, $write, $except, 0, 200_000) === false) {
                foreach ($pending as $name => $job) {
                    $results[$name] = new PrinterException("Sending to {$job['address']} failed.");
                    fclose($job['socket']);
                }
                break;
            }
            foreach (array_keys($pending) as $name) {
                $job = $pending[$name];
                if (! in_array($job['socket'], $write, true)) {
                    continue;
                }
                if (! $job['connected'] && @stream_socket_get_name($job['socket'], true) === false) {
                    $results[$name] = new PrinterException("Could not connect to the printer at {$job['address']}.");
                    fclose($job['socket']);
                    unset($pending[$name]);

                    continue;
                }
                $job['connected'] = true;
                $written = @fwrite($job['socket'], substr($job['bytes'], $job['sent'], self::CHUNK));
                if ($written === false) {
                    $results[$name] = new PrinterException("Sending to {$job['address']} stopped after {$job['sent']} of ".strlen($job['bytes']).' bytes.');
                    fclose($job['socket']);
                    unset($pending[$name]);

                    continue;
                }
                if ($written > 0) {
                    $job['sent'] += $written;
                    $job['deadline'] = microtime(true) + $job['timeout'];
                }
                if ($job['sent'] >= strlen($job['bytes'])) {
                    fclose($job['socket']);
                    unset($pending[$name]);

                    continue;
                }
                $pending[$name] = $job;
            }
            foreach ($pending as $name => $job) {
                if (microtime(true) > $job['deadline']) {
                    $results[$name] = new PrinterException("The printer at {$job['address']} did not respond within {$job['timeout']} seconds.");
                    fclose($job['socket']);
                    unset($pending[$name]);
                }
            }
        }

        foreach ($jobs as $name => [$printer, $bytes]) {
            if ($printer instanceof NetworkPrinter) {
                continue;
            }
            try {
                $printer->send($bytes);
            } catch (Throwable $e) {
                $results[$name] = $e;
            }
        }

        return $results;
    }
}
