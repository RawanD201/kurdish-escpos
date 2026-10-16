<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Tests;

use PHPUnit\Framework\TestCase;
use Rawand\KurdishEscpos\EscPos\Bitmap;

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
}
