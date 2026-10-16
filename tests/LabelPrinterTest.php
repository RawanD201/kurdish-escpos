<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Tests;

use PHPUnit\Framework\TestCase;
use Rawand\KurdishEscpos\EscPos\Bitmap;
use Rawand\KurdishEscpos\EscPos\PrinterOptions;
use Rawand\KurdishEscpos\KurdishEscpos;
use Rawand\KurdishEscpos\Printers\FilePrinter;
use Rawand\KurdishEscpos\Printers\PrinterSetup;
use Rawand\KurdishEscpos\Receipt;
use Rawand\KurdishEscpos\Renderer;

/**
 * Zebra (ZPL) and TSC (TSPL) output. The ZPL encoder was also checked with
 * the Labelary ZPL viewer: its rendering of the sample receipt matched the
 * preview dot for dot.
 */
final class LabelPrinterTest extends TestCase
{
    private static function randomBitmap(int $width, int $height, int $seed): Bitmap
    {
        mt_srand($seed);
        $stride = intdiv($width + 7, 8);
        [$rows, $row] = ['', ''];
        for ($y = 0; $y < $height; $y++) {
            // Blank rows, repeated rows and rows that end in white, as on a real receipt.
            $row = match ($y % 4) {
                1 => $row,
                2 => str_repeat("\0", $stride),
                default => substr(random_bytes($stride), 0, mt_rand(1, $stride)),
            };
            $row = str_pad($row, $stride, "\0");
            if ($width & 7) {
                $row[$stride - 1] = chr(ord($row[$stride - 1]) & (0xFF << (8 - ($width & 7))));
            }
            $rows .= $row;
        }

        return new Bitmap($width, $height, $rows);
    }

    public function test_zpl_graphic_field_with_compression(): void
    {
        // Rows: F000 (ends in white), the same row again, then 0001.
        $bitmap = new Bitmap(16, 3, "\xF0\x00\xF0\x00\x00\x01");

        $this->assertSame("^XA\n^PW16\n^LL3\n^FO0,0^GFA,6,6,2,F,:0001^FS\n^XZ\n", $bitmap->toZpl());
        $this->assertStringStartsWith(
            "~SD25^XA\n^PW16\n^LL1218\n",
            $bitmap->toZpl(new PrinterOptions(language: 'zpl', labelHeight: 1218, darkness: 25)),
        );
    }

    public function test_zpl_round_trip(): void
    {
        $bitmap = self::randomBitmap(813, 200, 7);

        $zpl = $bitmap->toZpl();

        $decoded = Bitmap::fromZpl($zpl);
        $this->assertSame([813, 200], [$decoded->width, $decoded->height]);
        $this->assertSame($bitmap->rows, $decoded->rows);
        $this->assertLessThan(2 * strlen($bitmap->rows), strlen($zpl), 'compressed');
    }

    public function test_tspl_bitmap_is_inverted(): void
    {
        // Dots 0, 1 and 9 are black. In TSPL a 0 bit prints, so the bits are
        // inverted, and the padding bits become 1 (white).
        $bitmap = new Bitmap(10, 2, "\xC0\x40\x00\x00");

        $this->assertSame(
            "SIZE 1.3 mm,0.3 mm\nGAP 0 mm,0 mm\nCLS\nBITMAP 0,0,2,2,0,\x3F\xBF\xFF\xFF\nPRINT 1,1\n",
            $bitmap->toTspl(),
        );
    }

    public function test_tspl_label_size_gap_and_density(): void
    {
        $bitmap = new Bitmap(812, 1, str_repeat("\0", 102));

        $tspl = $bitmap->toTspl(new PrinterOptions(language: 'tspl', labelHeight: 1218, labelGap: 3, darkness: 8));
        $this->assertStringStartsWith("SIZE 101.6 mm,152.4 mm\nGAP 3 mm,0 mm\nDENSITY 8\nCLS\n", $tspl);

        $tspl = $bitmap->toTspl(new PrinterOptions(language: 'tspl', labelHeight: 1800, dpi: 300));
        $this->assertStringStartsWith("SIZE 68.7 mm,152.4 mm\nGAP 2 mm,0 mm\nCLS\n", $tspl);
    }

    public function test_tspl_round_trip(): void
    {
        $bitmap = self::randomBitmap(400, 120, 8);

        $this->assertSame($bitmap->rows, Bitmap::fromTspl($bitmap->toTspl())->rows);
    }

    public function test_renderer_writes_the_printer_language(): void
    {
        $receipt = Receipt::make(406)->title('کوڵێرە')->row('نان', '1,000')->qr('https://example.com', 120)->openDrawer();

        foreach (['zpl', 'tspl'] as $language) {
            $rendered = (new Renderer(null, new PrinterOptions(language: $language)))->render($receipt);
            $image = imagecreatefromstring($rendered->png);
            $this->assertNotFalse($image);
            $preview = Bitmap::fromImage($image);

            $decoded = $language === 'zpl' ? Bitmap::fromZpl($rendered->commands()) : Bitmap::fromTspl($rendered->commands());
            $this->assertSame($preview->rows, $decoded->rows, "{$language} matches the preview");
            $this->assertSame($rendered->escpos, $rendered->commands());
            $this->assertStringNotContainsString("\x1B", $rendered->commands(), 'no ESC/POS cut or drawer commands');
        }
    }

    public function test_receipt_and_label_printers_together(): void
    {
        [$receiptFile, $labelFile] = [(string) tempnam(sys_get_temp_dir(), 'kurdish-escpos-test-'), (string) tempnam(sys_get_temp_dir(), 'kurdish-escpos-test-')];
        $escpos = new KurdishEscpos(new Renderer, [
            'cashier' => new FilePrinter($receiptFile),
            'labels' => new PrinterSetup(new FilePrinter($labelFile), width: 812, options: new PrinterOptions(language: 'zpl', labelHeight: 1218)),
        ], default: 'cashier');

        try {
            $result = $escpos->print(Receipt::make()->title('ڤیتامین')->row('پاراسیتامۆڵ', '2,500'), ['cashier', 'labels']);

            $this->assertTrue($result->ok());
            $this->assertStringStartsWith(Bitmap::INIT, (string) file_get_contents($receiptFile));
            $label = (string) file_get_contents($labelFile);
            $this->assertStringStartsWith("^XA\n^PW812\n^LL1218\n", $label);
            $this->assertSame(812, Bitmap::fromZpl($label)->width);
            $this->assertSame(812, getimagesizefromstring($result->rendered('labels')->png)[0]);
        } finally {
            @unlink($receiptFile);
            @unlink($labelFile);
        }
    }

    public function test_label_options(): void
    {
        foreach ([
            fn () => new PrinterOptions(language: 'epl'),
            fn () => new PrinterOptions(language: 'zpl', darkness: 31),
            fn () => new PrinterOptions(language: 'tspl', darkness: 16),
            fn () => new PrinterOptions(labelHeight: 0),
            fn () => new PrinterOptions(labelGap: -1),
            fn () => new PrinterOptions(dpi: 250),
        ] as $i => $make) {
            try {
                $make();
                $this->fail("case {$i} did not throw");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $options = PrinterOptions::fromArray(['language' => 'tspl', 'label_height' => '240', 'label_gap' => '0', 'darkness' => 10, 'dpi' => 300]);
        $this->assertSame(['tspl', 240, 0.0, 10, 300], [$options->language, $options->labelHeight, $options->labelGap, $options->darkness, $options->dpi]);
        $this->assertTrue($options->isLabel());

        // Empty .env values fall back to the defaults.
        $options = PrinterOptions::fromArray(['language' => '', 'image' => '', 'label_height' => '', 'darkness' => null]);
        $this->assertSame(['escpos', 'raster', null, null], [$options->language, $options->image, $options->labelHeight, $options->darkness]);
        $this->assertFalse($options->isLabel());
    }
}
