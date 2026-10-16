<?php

declare(strict_types=1);

namespace RawanD201\KurdishEscpos\Tests;

use PHPUnit\Framework\TestCase;
use RawanD201\KurdishEscpos\EscPos\Bitmap;
use RawanD201\KurdishEscpos\EscPos\PrinterOptions;

final class BitmapTest extends TestCase
{
    public function test_threshold_and_padding_bits(): void
    {
        $image = imagecreatetruecolor(10, 1);
        $gray = fn (int $v) => imagecolorallocate($image, $v, $v, $v);
        imagefilledrectangle($image, 0, 0, 9, 0, $gray(255));
        imagesetpixel($image, 0, 0, $gray(0));
        imagesetpixel($image, 1, 0, $gray(Bitmap::THRESHOLD - 1));
        imagesetpixel($image, 2, 0, $gray(Bitmap::THRESHOLD));
        imagesetpixel($image, 9, 0, $gray(10));

        $bitmap = Bitmap::fromImage($image);

        $this->assertSame(2, $bitmap->stride);
        // Dots 0, 1 and 9 are black; the 6 padding bits after dot 9 stay white.
        $this->assertSame("\xC0\x40", $bitmap->rows);
    }

    public function test_escpos_bands_and_round_trip(): void
    {
        mt_srand(1);
        $rows = '';
        for ($i = 0; $i < 72 * 600; $i++) {
            $rows .= chr(mt_rand(0, 255));
        }
        $bitmap = new Bitmap(576, 600, $rows); // bands of 256 + 256 + 88 rows

        $bytes = $bitmap->toEscPos();

        $this->assertStringStartsWith("\x1B\x40\x1D\x76\x30\x00\x48\x00\x00\x01", $bytes);
        $third = 2 + 2 * (8 + 72 * 256);
        $this->assertSame("\x1D\x76\x30\x00\x48\x00\x58\x00", substr($bytes, $third, 8));
        $this->assertStringEndsWith("\x1B\x64\x04\x1D\x56\x42\x00", $bytes);
        $this->assertSame($rows, Bitmap::fromEscPos($bytes)->rows);
    }

    public function test_preview_shows_the_same_dots(): void
    {
        $bitmap = new Bitmap(16, 2, "\x81\x00\xFF\x01");

        $preview = $bitmap->toImage();

        $this->assertSame($bitmap->rows, Bitmap::fromImage($preview)->rows);
    }

    public function test_column_mode_for_older_printers(): void
    {
        mt_srand(2);
        $rows = '';
        for ($i = 0; $i < 72 * 50; $i++) {
            $rows .= chr(mt_rand(0, 255));
        }
        $bitmap = new Bitmap(576, 50, $rows); // three stripes of 24 rows

        $bytes = $bitmap->toEscPos(new PrinterOptions(image: PrinterOptions::IMAGE_COLUMN));

        // ESC @, ESC 3 24, then ESC * 33 with 576 columns (0x0240).
        $this->assertStringStartsWith("\x1B\x40\x1B\x33\x18\x1B\x2A\x21\x40\x02", $bytes);
        $this->assertSame(3, substr_count($bytes, "\x1B\x2A\x21\x40\x02"));
        $decoded = Bitmap::fromEscPos($bytes);
        $this->assertSame(72, $decoded->height);
        $this->assertSame($rows, substr($decoded->rows, 0, strlen($rows)));
        $this->assertSame(str_repeat("\0", 72 * 22), substr($decoded->rows, strlen($rows)), 'padding rows are white');
    }

    public function test_feed_cut_and_cash_drawer(): void
    {
        $bitmap = new Bitmap(8, 1, "\x80");

        $this->assertStringEndsWith("\x1B\x64\x04\x1D\x56\x42\x00", $bitmap->toEscPos());
        $this->assertStringEndsWith("\x1B\x64\x02\x1D\x56\x41\x00", $bitmap->toEscPos(new PrinterOptions(cut: 'full', feed: 2)));
        $this->assertStringEndsWith("\x01\x00\x80", $bitmap->toEscPos(new PrinterOptions(cut: 'none', feed: 0)));
        $this->assertStringEndsWith("\x1D\x56\x42\x00\x1B\x70\x00\x19\xFA", $bitmap->toEscPos(null, true));
        $this->assertStringEndsWith("\x1B\x70\x01\x19\xFA", $bitmap->toEscPos(new PrinterOptions(drawerPin: 5), true));
        $this->assertSame("\x80", Bitmap::fromEscPos($bitmap->toEscPos(new PrinterOptions(cut: 'full'), true))->rows);
    }

    public function test_invalid_printer_options(): void
    {
        foreach ([
            fn () => new PrinterOptions(image: 'bitmap'),
            fn () => new PrinterOptions(cut: 'half'),
            fn () => new PrinterOptions(feed: 300),
            fn () => new PrinterOptions(drawerPin: 3),
        ] as $i => $make) {
            try {
                $make();
                $this->fail("case {$i} did not throw");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('column', PrinterOptions::fromArray(['image' => 'column'])->image);
    }
}
