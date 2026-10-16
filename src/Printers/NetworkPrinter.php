<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Printers;

use Rawand\KurdishEscpos\Exceptions\PrinterException;

/**
 * A network printer reached over raw TCP. Most receipt printers listen on
 * port 9100.
 */
final class NetworkPrinter implements Printer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port = 9100,
        private readonly float $timeout = 10.0,
    ) {}

    public function send(string $bytes): void
    {
        $host = str_contains($this->host, ':') ? "[{$this->host}]" : $this->host; // IPv6
        $address = "tcp://{$host}:{$this->port}";

        $socket = @stream_socket_client($address, $errno, $error, $this->timeout);
        if ($socket === false) {
            throw new PrinterException("Could not connect to the printer at {$address}: {$error} ({$errno}).");
        }

        try {
            stream_set_timeout($socket, (int) ceil($this->timeout));
            StreamWriter::writeAll($socket, $bytes, $address);
        } finally {
            fclose($socket);
        }
    }
}
