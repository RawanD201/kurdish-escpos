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

    public const CUT_FULL = "\x1D\x56\x41\x00";  // GS V A 0: full cut

    public const COLUMN = "\x1B\x2A\x21";         // ESC * 33: 24-dot double-density bit image

    private const LINE_SPACING_24 = "\x1B\x33\x18"; // ESC 3 24

    private const LINE_SPACING_DEFAULT = "\x1B\x32"; // ESC 2

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

    /**
     * Returns the commands for the printer's language: ESC/POS for receipt
     * printers, ZPL for Zebra or TSPL for TSC label printers.
     */
    public function toCommands(?PrinterOptions $options = null, bool $openDrawer = false): string
    {
        $options ??= new PrinterOptions;

        return match ($options->language) {
            PrinterOptions::LANGUAGE_ZPL => $this->toZpl($options),
            PrinterOptions::LANGUAGE_TSPL => $this->toTspl($options),
            default => $this->toEscPos($options, $openDrawer),
        };
    }

    /**
     * Returns a ZPL label for Zebra printers. The image is sent as a ^GFA
     * graphic field, compressed with ZPL's ASCII compression: "," ends a row
     * whose remaining dots are white, and ":" repeats the previous row.
     */
    public function toZpl(?PrinterOptions $options = null): string
    {
        $options ??= new PrinterOptions(language: PrinterOptions::LANGUAGE_ZPL);
        $data = '';
        $previous = null;
        for ($y = 0; $y < $this->height; $y++) {
            $row = strtoupper(bin2hex(substr($this->rows, $y * $this->stride, $this->stride)));
            if ($row === $previous) {
                $data .= ':';

                continue;
            }
            $previous = $row;
            $trimmed = rtrim($row, '0');
            $data .= strlen($trimmed) === strlen($row) ? $row : $trimmed.',';
        }
        $total = $this->stride * $this->height;
        $out = $options->darkness === null ? '' : sprintf('~SD%02d', $options->darkness);

        return $out."^XA\n^PW{$this->width}\n^LL".($options->labelHeight ?? $this->height)."\n"
            ."^FO0,0^GFA,{$total},{$total},{$this->stride},{$data}^FS\n^XZ\n";
    }

    /**
     * Returns a TSPL label for TSC printers. In TSPL bitmaps a 0 bit is a
     * black dot, so the image bits are inverted.
     */
    public function toTspl(?PrinterOptions $options = null): string
    {
        $options ??= new PrinterOptions(language: PrinterOptions::LANGUAGE_TSPL);
        $mm = fn (int $dots): string => rtrim(rtrim(number_format($dots * 25.4 / $options->dpi, 1, '.', ''), '0'), '.');
        $height = $options->labelHeight ?? $this->height;
        $gap = $options->labelHeight === null ? '0' : rtrim(rtrim(number_format($options->labelGap, 1, '.', ''), '0'), '.');

        $out = 'SIZE '.$mm($this->width).' mm,'.$mm($height)." mm\n";
        $out .= "GAP {$gap} mm,0 mm\n";
        if ($options->darkness !== null) {
            $out .= "DENSITY {$options->darkness}\n";
        }
        $out .= "CLS\n";
        $out .= "BITMAP 0,0,{$this->stride},{$this->height},0,".(~$this->rows)."\n";

        return $out."PRINT 1,1\n";
    }

    /**
     * Returns the printer command stream: initialize, the image, paper feed,
     * cut and, if requested, the pulse that opens the cash drawer.
     */
    public function toEscPos(?PrinterOptions $options = null, bool $openDrawer = false): string
    {
        $options ??= new PrinterOptions;
        $out = self::INIT;
        $out .= $options->image === PrinterOptions::IMAGE_COLUMN ? $this->columnImage() : $this->rasterImage();
        if ($options->feed > 0) {
            $out .= "\x1B\x64".chr($options->feed);
        }
        $out .= match ($options->cut) {
            PrinterOptions::CUT_FULL => self::CUT_FULL,
            PrinterOptions::CUT_PARTIAL => self::CUT,
            default => '',
        };
        if ($openDrawer) {
            // ESC p m t1 t2: pulse pin 2 (m = 0) or pin 5 (m = 1) for 50 ms on, 500 ms off.
            $out .= "\x1B\x70".($options->drawerPin === 5 ? "\x01" : "\x00")."\x19\xFA";
        }

        return $out;
    }

    /** The image as GS v 0 raster bands. */
    private function rasterImage(): string
    {
        $out = '';
        for ($y = 0; $y < $this->height; $y += self::BAND_ROWS) {
            $rows = min(self::BAND_ROWS, $this->height - $y);
            $out .= self::RASTER.pack('vv', $this->stride, $rows).substr($this->rows, $y * $this->stride, $rows * $this->stride);
        }

        return $out;
    }

    /**
     * The image as ESC * stripes, 24 dots high, for printers that do not
     * support GS v 0. Line spacing is set to 24 dots so the stripes touch.
     */
    private function columnImage(): string
    {
        $out = self::LINE_SPACING_24;
        for ($y = 0; $y < $this->height; $y += 24) {
            $data = '';
            for ($x = 0; $x < $this->width; $x++) {
                $byteIndex = $x >> 3;
                $mask = 0x80 >> ($x & 7);
                for ($k = 0; $k < 24; $k += 8) {
                    $byte = 0;
                    for ($bit = 0; $bit < 8; $bit++) {
                        $row = $y + $k + $bit;
                        if ($row < $this->height && (ord($this->rows[$row * $this->stride + $byteIndex]) & $mask)) {
                            $byte |= 0x80 >> $bit;
                        }
                    }
                    $data .= chr($byte);
                }
            }
            $out .= self::COLUMN.pack('v', $this->width).$data."\n";
        }

        return $out.self::LINE_SPACING_DEFAULT;
    }

    /**
     * Parses a command stream produced by toEscPos(), in either image mode.
     * Used to verify output against the preview. In column mode the height
     * is rounded up to a multiple of 24 rows.
     */
    public static function fromEscPos(string $bytes): self
    {
        if (! str_starts_with($bytes, self::INIT)) {
            throw new RenderException('Not ESC/POS data from this package.');
        }
        $length = strlen($bytes);
        $p = strlen(self::INIT);
        [$width, $stride, $height, $rows] = [0, 0, 0, ''];
        while ($p < $length) {
            $command = substr($bytes, $p, 4);
            if ($command === self::RASTER) {
                ['s' => $bandStride, 'r' => $bandRows] = unpack('vs/vr', $bytes, $p + 4);
                if ($stride !== 0 && $bandStride !== $stride) {
                    throw new RenderException('Raster bands have different widths.');
                }
                [$stride, $width] = [$bandStride, $bandStride * 8];
                $rows .= substr($bytes, $p + 8, $stride * $bandRows);
                $height += $bandRows;
                $p += 8 + $stride * $bandRows;
            } elseif (str_starts_with($command, self::COLUMN)) {
                $width = unpack('v', $bytes, $p + 3)[1];
                $stride = intdiv($width + 7, 8);
                $data = substr($bytes, $p + 5, 3 * $width);
                $stripe = array_fill(0, 24, array_fill(0, $stride, 0));
                for ($x = 0; $x < $width; $x++) {
                    for ($k = 0; $k < 24; $k++) {
                        if (ord($data[3 * $x + ($k >> 3)]) & (0x80 >> ($k & 7))) {
                            $stripe[$k][$x >> 3] |= 0x80 >> ($x & 7);
                        }
                    }
                }
                foreach ($stripe as $row) {
                    $rows .= pack('C*', ...$row);
                }
                $height += 24;
                $p += 5 + 3 * $width + 1; // the image data is followed by LF
            } elseif (str_starts_with($command, self::LINE_SPACING_24)) {
                $p += 3;
            } elseif (str_starts_with($command, self::LINE_SPACING_DEFAULT)) {
                $p += 2;
            } elseif (str_starts_with($command, "\x1B\x64")) { // ESC d n: feed
                $p += 3;
            } elseif ($command === self::CUT || $command === self::CUT_FULL) {
                $p += 4;
            } elseif (str_starts_with($command, "\x1B\x70")) { // ESC p m t1 t2: cash drawer
                $p += 5;
            } else {
                throw new RenderException("Unexpected bytes at offset {$p}.");
            }
        }

        return new self($width, $height, $rows);
    }

    /** Reads back the image from a ZPL label produced by toZpl(). Used by the tests. */
    public static function fromZpl(string $zpl): self
    {
        if (! preg_match('/\^PW(\d+).*?\^GFA,(\d+),\d+,(\d+),([0-9A-F,:]*)\^FS/s', $zpl, $m)) {
            throw new RenderException('No ^GFA graphic field found.');
        }
        [$width, $total, $stride, $data] = [(int) $m[1], (int) $m[2], (int) $m[3], $m[4]];
        $rows = '';
        $previous = str_repeat('0', 2 * $stride);
        $row = '';
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $c = $data[$i];
            if ($c === ':') {
                $rows .= hex2bin($previous);

                continue;
            }
            if ($c === ',') {
                $row = str_pad($row, 2 * $stride, '0');
            } else {
                $row .= $c;
            }
            if (strlen($row) === 2 * $stride) {
                $rows .= hex2bin($row);
                [$previous, $row] = [$row, ''];
            }
        }

        return new self($width, intdiv($total, $stride), $rows);
    }

    /** Reads back the image from a TSPL label produced by toTspl(). Used by the tests. */
    public static function fromTspl(string $tspl): self
    {
        if (! preg_match('/BITMAP 0,0,(\d+),(\d+),0,/', $tspl, $m, PREG_OFFSET_CAPTURE)) {
            throw new RenderException('No BITMAP command found.');
        }
        [$stride, $height] = [(int) $m[1][0], (int) $m[2][0]];
        $start = $m[0][1] + strlen($m[0][0]);

        return new self($stride * 8, $height, ~substr($tspl, $start, $stride * $height));
    }
}
