<?php

declare(strict_types=1);

namespace RawanD201\KurdishEscpos\Tests;

use PHPUnit\Framework\TestCase;
use RawanD201\KurdishEscpos\EscPos\Bitmap;
use RawanD201\KurdishEscpos\EscPos\PrinterOptions;
use RawanD201\KurdishEscpos\Exceptions\PrinterException;
use RawanD201\KurdishEscpos\KurdishEscpos;
use RawanD201\KurdishEscpos\Printers\FilePrinter;
use RawanD201\KurdishEscpos\Printers\NetworkPrinter;
use RawanD201\KurdishEscpos\Printers\ParallelSender;
use RawanD201\KurdishEscpos\Printers\PrinterSetup;
use RawanD201\KurdishEscpos\Receipt;
use RawanD201\KurdishEscpos\Renderer;

final class MultiPrinterTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function file(): string
    {
        return $this->files[] = (string) tempnam(sys_get_temp_dir(), 'kurdish-escpos-test-');
    }

    private function receipt(): Receipt
    {
        return Receipt::make()->title('کوڵێرە')->row('نان', '1,000');
    }

    public function test_prints_on_several_printers_with_their_own_settings(): void
    {
        [$wide, $narrow] = [$this->file(), $this->file()];
        $escpos = new KurdishEscpos(new Renderer, [
            'cashier' => new FilePrinter($wide),
            'kitchen' => new PrinterSetup(new FilePrinter($narrow), width: 384, options: new PrinterOptions(image: 'column', cut: 'none')),
        ], default: 'cashier');

        $result = $escpos->print($this->receipt(), ['cashier', 'kitchen']);

        $this->assertTrue($result->ok());
        $this->assertSame(['cashier', 'kitchen'], $result->printed());
        $this->assertSame(576, Bitmap::fromEscPos((string) file_get_contents($wide))->width);
        $kitchen = (string) file_get_contents($narrow);
        $this->assertStringContainsString(Bitmap::COLUMN."\x80\x01", $kitchen, 'ESC * with 384 columns');
        $this->assertStringNotContainsString(Bitmap::CUT, $kitchen);
        $this->assertSame(384, getimagesizefromstring($result->rendered('kitchen')->png)[0]);
    }

    public function test_a_failing_printer_does_not_stop_the_others(): void
    {
        $good = $this->file();
        $escpos = new KurdishEscpos(new Renderer, [
            'good' => new FilePrinter($good),
            'broken' => new FilePrinter('/no/such/dir/lp0'),
        ]);

        $result = $escpos->print($this->receipt(), ['broken', 'good']);

        $this->assertFalse($result->ok());
        $this->assertSame(['good'], $result->printed());
        $this->assertArrayHasKey('broken', $result->failures());
        $this->assertStringStartsWith(Bitmap::INIT, (string) file_get_contents($good));

        $this->expectException(PrinterException::class);
        $this->expectExceptionMessage('broken');
        $result->throw();
    }

    public function test_a_single_printer_failure_throws(): void
    {
        $escpos = new KurdishEscpos(new Renderer, ['broken' => new FilePrinter('/no/such/dir/lp0')], default: 'broken');

        $this->expectException(PrinterException::class);
        $escpos->print($this->receipt());
    }

    public function test_unknown_printer_name(): void
    {
        $escpos = new KurdishEscpos(new Renderer, ['cashier' => new FilePrinter($this->file()), 'kitchen' => new FilePrinter($this->file())]);

        $this->expectException(PrinterException::class);
        $this->expectExceptionMessage('Unknown printer "bar". Configured printers: cashier, kitchen.');
        $escpos->print($this->receipt(), 'bar');
    }

    public function test_receipts_take_the_printer_width(): void
    {
        $escpos = new KurdishEscpos(new Renderer, [
            'default' => new FilePrinter($this->file()),
            'kitchen' => new PrinterSetup(new FilePrinter($this->file()), width: 384),
        ]);

        $this->assertSame(576, $escpos->receipt()->toArray()['width']);
        $this->assertSame(384, $escpos->receipt('kitchen')->toArray()['width']);
        $this->assertSame(['default', 'kitchen'], $escpos->printerNames());
        $this->assertSame('default', $escpos->defaultPrinterName());
    }

    public function test_printers_with_the_same_settings_share_one_render(): void
    {
        $escpos = new KurdishEscpos(new Renderer, ['a' => new FilePrinter($this->file()), 'b' => new FilePrinter($this->file())]);

        $result = $escpos->print($this->receipt(), ['a', 'b']);

        $this->assertSame($result->rendered('a'), $result->rendered('b'));
    }

    public function test_print_each_sends_different_receipts(): void
    {
        [$cashier, $kitchen] = [$this->file(), $this->file()];
        $escpos = new KurdishEscpos(new Renderer, ['cashier' => new FilePrinter($cashier), 'kitchen' => new FilePrinter($kitchen)]);

        $result = $escpos->printEach([
            'cashier' => $this->receipt()->row('کۆی گشتی', '1,000'),
            'kitchen' => Receipt::make()->title('نان × ٢'),
        ]);

        $this->assertTrue($result->ok());
        $this->assertNotSame(file_get_contents($cashier), file_get_contents($kitchen));
        $this->assertSame($result->rendered('kitchen')->escpos, file_get_contents($kitchen));
    }

    /**
     * Two network printers that each take a second before they read must
     * both finish in about one second, which proves they were sent to in
     * parallel. A third printer that does not exist fails on its own.
     */
    public function test_network_printers_receive_data_in_parallel(): void
    {
        $bytes = random_bytes(8_000_000); // larger than the socket buffers, so sending must wait for the reader
        [$a, $aPipes, $aPort] = self::slowPrinter(1.0);
        [$b, $bPipes, $bPort] = self::slowPrinter(1.0);
        $closed = stream_socket_server('tcp://127.0.0.1:0');
        $closedPort = (int) substr((string) strrchr((string) stream_socket_get_name($closed, false), ':'), 1);
        fclose($closed);

        $start = microtime(true);
        $results = ParallelSender::send([
            'a' => [new NetworkPrinter('127.0.0.1', $aPort, 10), $bytes],
            'b' => [new NetworkPrinter('127.0.0.1', $bPort, 10), $bytes],
            'missing' => [new NetworkPrinter('127.0.0.1', $closedPort, 2), $bytes],
        ]);
        $elapsed = microtime(true) - $start;

        $expected = strlen($bytes).' '.hash('sha256', $bytes);
        $this->assertSame($expected, trim((string) stream_get_contents($aPipes[1])));
        $this->assertSame($expected, trim((string) stream_get_contents($bPipes[1])));
        proc_close($a);
        proc_close($b);
        $this->assertNull($results['a']);
        $this->assertNull($results['b']);
        $this->assertInstanceOf(PrinterException::class, $results['missing']);
        $this->assertLessThan(1.8, $elapsed, 'sent in parallel, not one after another');
    }

    /**
     * A fake printer in a separate PHP process that waits before reading.
     *
     * @return array{resource, array<int, resource>, int}
     */
    private static function slowPrinter(float $delay): array
    {
        $code = <<<PHP
            \$server = stream_socket_server('tcp://127.0.0.1:0');
            echo substr(strrchr(stream_socket_get_name(\$server, false), ':'), 1), "\\n";
            \$connection = stream_socket_accept(\$server, 10);
            usleep((int) ({$delay} * 1_000_000));
            \$data = stream_get_contents(\$connection);
            echo strlen(\$data), ' ', hash('sha256', \$data), "\\n";
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
        $port = (int) fgets($pipes[1]);

        return [$process, $pipes, $port];
    }
}
