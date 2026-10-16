<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\EscPos;

use GdImage;
use Rawand\KurdishEscpos\Exceptions\RenderException;

/**
 * A monochrome image in the printer's raster format. Each row is $stride
 * bytes, the leftmost dot is the most significant bit, and 1 is black.
 */
final class Bitmap
{
    /** Gray levels below this value print black. It is above 128 so that thin strokes remain visible. */
    public const THRESHOLD = 160;

    /** Maximum rows per GS v 0 command. Tall images are sent in bands to suit small printer buffers. */
    public const BAND_ROWS = 256;

    public const INIT = "\x1B\x40";              // ESC @: initialize the printer

    public const RASTER = "\x1D\x76\x30\x00";    // GS v 0, normal density

    public const FEED = "\x1B\x64\x04";          // ESC d 4: feed 4 lines

    public const CUT = "\x1D\x56\x42\x00";       // GS V B 0: partial cut

    public readonly int $stride;

    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly string $rows,
    ) {
        $this->stride = intdiv($width + 7, 8);
        if (strlen($rows) !== $this->stride * $height) {
            throw new \InvalidArgumentException('Bitmap data does not match its size.');
        }
    }

    /** Converts a GD image to a bitmap. Pixels darker than the threshold become black dots. */
    public static function fromImage(GdImage $image, int $threshold = self::THRESHOLD): self
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $rows = '';
        $limit = $threshold * 256; // luminance scaled by 256
        $truecolor = imageistruecolor($image);
        for ($y = 0; $y < $height; $y++) {
            $byte = 0;
            for ($x = 0; $x < $width; $x++) {
                $c = imagecolorat($image, $x, $y);
                if (! $truecolor) {
                    $rgba = imagecolorsforindex($image, $c);
                    $c = ($rgba['red'] << 16) | ($rgba['green'] << 8) | $rgba['blue'];
                }
                if (77 * (($c >> 16) & 0xFF) + 150 * (($c >> 8) & 0xFF) + 29 * ($c & 0xFF) < $limit) {
                    $byte |= 0x80 >> ($x & 7);
                }
                if (($x & 7) === 7) {
                    $rows .= chr($byte);
                    $byte = 0;
                }
            }
            if ($width & 7) {
                $rows .= chr($byte);
            }
        }

        return new self($width, $height, $rows);
    }

    public function isBlack(int $x, int $y): bool
    {
        return (ord($this->rows[$y * $this->stride + ($x >> 3)]) & (0x80 >> ($x & 7))) !== 0;
    }

    /** Returns a two-color GD image containing exactly the dots that will be printed. */
    public function toImage(): GdImage
    {
        $image = imagecreate($this->width, max(1, $this->height));
        imagecolorallocate($image, 255, 255, 255);
        $ink = imagecolorallocate($image, 0, 0, 0);
        for ($y = 0; $y < $this->height; $y++) {
            $row = substr($this->rows, $y * $this->stride, $this->stride);
            $start = null;
            for ($x = 0; $x <= $this->width; $x++) {
                // Skip empty bytes quickly; most of a receipt is blank.
                if ($start === null && ($x & 7) === 0 && $x < $this->width && $row[$x >> 3] === "\0") {
                    $x += 7;

                    continue;
                }
                $dot = $x < $this->width && (ord($row[$x >> 3]) & (0x80 >> ($x & 7)));
                if ($dot && $start === null) {
                    $start = $x;
                } elseif (! $dot && $start !== null) {
                    imagefilledrectangle($image, $start, $y, $x - 1, $y, $ink);
                    $start = null;
                }
            }
        }

        return $image;
    }

    public function toPng(): string
    {
        ob_start();
        imagepng($this->toImage(), null, 9);

        return (string) ob_get_clean();
    }

    /** Returns the printer command stream: initialize, raster bands, feed and partial cut. */
    public function toEscPos(): string
    {
        $out = self::INIT;
        for ($y = 0; $y < $this->height; $y += self::BAND_ROWS) {
            $rows = min(self::BAND_ROWS, $this->height - $y);
            $out .= self::RASTER.pack('vv', $this->stride, $rows).substr($this->rows, $y * $this->stride, $rows * $this->stride);
        }

        return $out.self::FEED.self::CUT;
    }

    /** Parses a command stream produced by toEscPos(). Used to verify output against the preview. */
    public static function fromEscPos(string $bytes): self
    {
        if (! str_starts_with($bytes, self::INIT) || ! str_ends_with($bytes, self::FEED.self::CUT)) {
            throw new RenderException('Not ESC/POS raster data from this package.');
        }
        $p = strlen(self::INIT);
        $end = strlen($bytes) - strlen(self::FEED.self::CUT);
        [$stride, $height, $rows] = [0, 0, ''];
        while ($p < $end) {
            if (substr($bytes, $p, 4) !== self::RASTER) {
                throw new RenderException("Unexpected bytes at offset {$p}.");
            }
            ['s' => $bandStride, 'r' => $bandRows] = unpack('vs/vr', $bytes, $p + 4);
            if ($stride !== 0 && $bandStride !== $stride) {
                throw new RenderException('Raster bands have different widths.');
            }
            $stride = $bandStride;
            $rows .= substr($bytes, $p + 8, $stride * $bandRows);
            $height += $bandRows;
            $p += 8 + $stride * $bandRows;
        }

        return new self($stride * 8, $height, $rows);
    }
}
