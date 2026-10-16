<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Tests;

use PHPUnit\Framework\TestCase;
use Rawand\KurdishEscpos\Exceptions\PrinterException;
use Rawand\KurdishEscpos\Printers\FilePrinter;
use Rawand\KurdishEscpos\Printers\NetworkPrinter;

final class PrinterTest extends TestCase
{
    public function test_network_printer_sends_every_byte(): void
    {
        // A fake printer in a separate PHP process, so it can read while this
        // process blocks sending a payload larger than the socket buffer.
        $server = <<<'PHP'
            $server = stream_socket_server('tcp://127.0.0.1:0');
            echo substr(strrchr(stream_socket_get_name($server, false), ':'), 1), "\n";
            $data = stream_get_contents(stream_socket_accept($server, 10));
            echo strlen($data), ' ', hash('sha256', $data), "\n";
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $server], [1 => ['pipe', 'w']], $pipes);
        $port = (int) fgets($pipes[1]);

        $bytes = "\x1B\x40".random_bytes(500_000)."\x1D\x56\x42\x00";
        (new NetworkPrinter('127.0.0.1', $port, 5))->send($bytes);

        $received = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        proc_close($process);
        $this->assertSame(strlen($bytes).' '.hash('sha256', $bytes), $received);
    }

    public function test_network_printer_reports_connection_errors(): void
    {
        // Grab a free port, then close it so nothing listens there.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        fclose($server);

        $this->expectException(PrinterException::class);
        $this->expectExceptionMessage("tcp://127.0.0.1:{$port}");
        (new NetworkPrinter('127.0.0.1', $port, 2))->send('x');
    }

    public function test_file_printer_writes_to_the_device(): void
    {
        $device = tempnam(sys_get_temp_dir(), 'kurdish-escpos-test-');
        try {
            (new FilePrinter($device))->send("\x1B\x40hello");
            $this->assertSame("\x1B\x40hello", file_get_contents($device));
        } finally {
            unlink($device);
        }
    }

    public function test_file_printer_reports_missing_device(): void
    {
        $this->expectException(PrinterException::class);
        $this->expectExceptionMessage('/no/such/dir/lp0');
        (new FilePrinter('/no/such/dir/lp0'))->send('x');
    }
}
