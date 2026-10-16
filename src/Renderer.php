<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use GdImage;
use JsonException;
use Rawand\KurdishEscpos\EscPos\Bitmap;
use Rawand\KurdishEscpos\EscPos\PrinterOptions;
use Rawand\KurdishEscpos\Exceptions\RenderException;
use Rawand\KurdishEscpos\Image\Rasterizer;
use Rawand\KurdishEscpos\Text\Font;
use Rawand\KurdishEscpos\Text\Line;
use Rawand\KurdishEscpos\Text\TextLayout;
use Throwable;

/**
 * Renders receipts with GD and encodes them as ESC/POS commands.
 *
 * Each item is drawn into its own horizontal band and the bands are stacked.
 * Text is drawn at four times the target resolution and scaled down, which
 * produces smooth edges before the image is reduced to black and white.
 */
final class Renderer
{
    public const DEFAULT_MARGIN = 8;

    public const DEFAULT_SIZE = 24;

    public const DEFAULT_QR_SIZE = 240;

    private const MAX_WIDTH = 2048;

    private const FEED_LINE = 30;

    private const SUPERSAMPLE = 4;

    private const ITEM_FIELDS = [
        'text' => ['text', 'size', 'align'],
        'row' => ['name', 'price', 'size'],
        'divider' => [],
        'qr' => ['data', 'size'],
        'feed' => ['lines'],
    ];

    private readonly TextLayout $layout;

    private readonly Rasterizer $rasterizer;

    private int $width = Receipt::WIDTH_80MM;

    private int $left = 0;

    private int $right = 0;

    private ?GdImage $canvas = null;

    private readonly PrinterOptions $options;

    /**
     * @param  Font|string|null  $font  A font, or the path to a .ttf file. When null, the
     *                                  bundled Vazirmatn font is used. Vazirmatn is also
     *                                  the fallback for characters the font lacks.
     * @param  PrinterOptions|null  $options  Image command, cut and feed settings for the printer.
     */
    public function __construct(Font|string|null $font = null, ?PrinterOptions $options = null)
    {
        if (! extension_loaded('gd')) {
            throw new RenderException('The PHP GD extension is required to draw receipts.');
        }
        $main = is_string($font) ? Font::load($font) : ($font ?? Font::default());
        $fallback = Font::default();
        $this->layout = new TextLayout($main === $fallback ? [$main] : [$main, $fallback]);
        $this->rasterizer = new Rasterizer;
        $this->options = $options ?? new PrinterOptions;
    }

    /**
     * @param  Receipt|array<string, mixed>|string  $receipt  a builder, an array, or receipt JSON
     */
    public function render(Receipt|array|string $receipt): RenderedReceipt
    {
        $data = $this->normalize($receipt);
        $drawer = $data['drawer'] ?? false;
        if (! is_bool($drawer)) {
            throw new RenderException('"drawer" must be true or false.');
        }
        $bitmap = Bitmap::fromImage($this->draw($data));

        return new RenderedReceipt($bitmap->toPng(), $bitmap->toEscPos($this->options, $drawer));
    }

    /**
     * Draws the receipt as a grayscale GD image, before conversion to black
     * and white.
     *
     * @param  Receipt|array<string, mixed>|string  $receipt
     */
    public function draw(Receipt|array|string $receipt): GdImage
    {
        $receipt = $this->normalize($receipt);
        $width = $receipt['width'] ?? Receipt::WIDTH_80MM;
        $margin = $receipt['margin'] ?? self::DEFAULT_MARGIN;
        if (! is_int($width) || $width < 1 || $width > self::MAX_WIDTH) {
            throw new RenderException('Width must be a whole number from 1 to '.self::MAX_WIDTH.' dots.');
        }
        $this->width = $width;
        if (! is_int($margin) || $margin < 0 || 2 * $margin >= $this->width) {
            throw new RenderException("Margin {$margin} does not fit in width {$this->width}.");
        }
        [$this->left, $this->right] = [$margin, $this->width - $margin];
        $items = $receipt['items'] ?? [];
        if (! is_array($items) || $items === []) {
            throw new RenderException('The receipt has no items.');
        }

        $bands = [];
        foreach (array_values($items) as $i => $item) {
            try {
                $bands[] = $this->band(is_array($item) ? $item : []);
            } catch (RenderException $e) {
                $type = is_array($item) && is_string($item['type'] ?? null) ? $item['type'] : '?';
                throw new RenderException("Item {$i} ({$type}): {$e->getMessage()}", 0, $e);
            }
        }

        $height = $margin + array_sum(array_map(imagesy(...), $bands));
        $image = $this->blank($this->width, $height);
        $y = $margin;
        foreach ($bands as $band) {
            imagecopy($image, $band, 0, $y, 0, 0, $this->width, imagesy($band));
            $y += imagesy($band);
        }

        return $image;
    }

    /**
     * @param  Receipt|array<string, mixed>|string  $receipt
     * @return array<string, mixed>
     */
    private function normalize(Receipt|array|string $receipt): array
    {
        if ($receipt instanceof Receipt) {
            return $receipt->toArray();
        }
        if (is_string($receipt)) {
            try {
                $receipt = json_decode($receipt, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RenderException('Invalid receipt JSON: '.$e->getMessage(), 0, $e);
            }
            if (! is_array($receipt)) {
                throw new RenderException('Receipt JSON must be an object.');
            }
        }
        if ($unknown = array_diff(array_keys($receipt), ['width', 'margin', 'drawer', 'items'])) {
            throw new RenderException('Unknown receipt field "'.reset($unknown).'".');
        }

        return $receipt;
    }

    /** @param  array<string, mixed>  $item */
    private function band(array $item): GdImage
    {
        $type = $item['type'] ?? null;
        if (! is_string($type) || ! isset(self::ITEM_FIELDS[$type])) {
            throw new RenderException($type === null
                ? 'Missing "type".'
                : 'Unknown type "'.(is_string($type) ? $type : gettype($type)).'" (use text, row, divider, qr or feed).');
        }
        if ($unknown = array_diff(array_keys($item), ['type', ...self::ITEM_FIELDS[$type]])) {
            throw new RenderException('Unknown field "'.reset($unknown).'".');
        }
        $size = $item['size'] ?? null;
        if ($size !== null && (! is_int($size) && ! is_float($size) || $size <= 0 || $size > 500)) {
            throw new RenderException('Size must be a positive number of dots.');
        }

        return match ($type) {
            'text' => $this->textBand(self::string($item, 'text'), (float) ($size ?? self::DEFAULT_SIZE), $item['align'] ?? 'start'),
            'row' => $this->rowBand(self::string($item, 'name'), self::string($item, 'price'), (float) ($size ?? self::DEFAULT_SIZE)),
            'divider' => $this->dividerBand(),
            'qr' => $this->qrBand(self::string($item, 'data'), (int) ($size ?? self::DEFAULT_QR_SIZE)),
            'feed' => $this->feedBand($item['lines'] ?? 1),
        };
    }

    private function textBand(string $text, float $size, mixed $align): GdImage
    {
        if (! in_array($align, ['start', 'center', 'end'], true)) {
            throw new RenderException('Unknown align "'.(is_string($align) ? $align : gettype($align)).'" (use start, center or end).');
        }
        $lines = $this->layout->layout($text, $size, $this->right - $this->left);
        $height = (int) ceil(array_sum(array_map(fn (Line $l) => $l->height(), $lines)));

        return $this->drawText($height, function (callable $draw) use ($lines, $align) {
            $y = 0.0;
            foreach ($lines as $line) {
                $draw($line, $this->alignX($line, $align), $y + $line->ascent);
                $y += $line->height();
            }
        });
    }

    /** Returns the left edge of a line. "start" is the right side for right-to-left text and the left side otherwise. */
    private function alignX(Line $line, string $align): float
    {
        return match (true) {
            $align === 'center' => $this->left + ($this->right - $this->left - $line->width) / 2,
            ($align === 'end') === $line->rtl => (float) $this->left,
            default => $this->right - $line->width,
        };
    }

    /**
     * Draws an item name, wrapped when long, and a price on one row. The name
     * starts at the beginning of the line in its own direction: on the right
     * for right-to-left text and on the left for left-to-right text. The
     * price is at the other end. The first lines of both share a baseline.
     */
    private function rowBand(string $name, string $price, float $size): GdImage
    {
        $contentWidth = $this->right - $this->left;
        $priceLines = $this->layout->layout($price, $size, $contentWidth / 2);
        $priceWidth = max(array_map(fn (Line $l) => $l->width, $priceLines));
        $nameWidth = max($contentWidth - $priceWidth - $size / 2, $contentWidth / 3);
        $nameLines = $this->layout->layout($name, $size, $nameWidth);

        $baseline = max($nameLines[0]->ascent, $priceLines[0]->ascent);
        $height = (int) ceil(max(
            $baseline + array_sum(array_map(fn (Line $l) => $l->height(), $nameLines)) - $nameLines[0]->ascent,
            $baseline + array_sum(array_map(fn (Line $l) => $l->height(), $priceLines)) - $priceLines[0]->ascent,
        ));

        $rtl = $nameLines[0]->rtl;

        return $this->drawText($height, function (callable $draw) use ($nameLines, $priceLines, $baseline, $rtl) {
            $y = $baseline;
            foreach ($nameLines as $i => $line) {
                $y += $i > 0 ? $nameLines[$i - 1]->descent + $line->ascent : 0;
                $draw($line, $rtl ? $this->right - $line->width : (float) $this->left, $y);
            }
            $y = $baseline;
            foreach ($priceLines as $i => $line) {
                $y += $i > 0 ? $priceLines[$i - 1]->descent + $line->ascent : 0;
                $draw($line, $rtl ? (float) $this->left : $this->right - $line->width, $y);
            }
        });
    }

    /**
     * Draws text at SUPERSAMPLE times the target size and scales it down into
     * the band. Only the inked rectangle of each line is resampled, since
     * resampling is the most expensive step.
     *
     * @param  callable(callable(Line, float, float): void): void  $paint
     */
    private function drawText(int $height, callable $paint): GdImage
    {
        $zoom = self::SUPERSAMPLE;
        $big = $this->supersampleCanvas($this->width * $zoom, max(1, $height) * $zoom);
        $band = $this->blank($this->width, max(1, $height));
        $white = imagecolorallocate($big, 255, 255, 255);
        $ink = imagecolorallocate($big, 0, 0, 0);

        $paint(function (Line $line, float $x, float $y) use ($big, $band, $zoom, $ink, $white) {
            $bounds = $this->rasterizer->drawLine($big, $line, $x, $y, $zoom, $ink);
            if ($bounds === null) {
                return;
            }
            // Band pixels covering the inked rectangle.
            [$left, $top] = [intdiv($bounds[0], $zoom), intdiv($bounds[1], $zoom)];
            [$right, $bottom] = [min(imagesx($band) - 1, intdiv($bounds[2], $zoom)), min(imagesy($band) - 1, intdiv($bounds[3], $zoom))];
            [$w, $h] = [$right - $left + 1, $bottom - $top + 1];
            // Multiply rather than copy, so that touching lines do not erase each other.
            $piece = imagecreatetruecolor($w, $h);
            imagecopyresampled($piece, $big, 0, 0, $left * $zoom, $top * $zoom, $w, $h, $w * $zoom, $h * $zoom);
            imagefilledrectangle($big, $left * $zoom, $top * $zoom, ($right + 1) * $zoom - 1, ($bottom + 1) * $zoom - 1, $white);
            imagelayereffect($band, IMG_EFFECT_MULTIPLY);
            imagecopy($band, $piece, $left, $top, 0, 0, $w, $h);
            imagelayereffect($band, IMG_EFFECT_REPLACE);
        });

        return $band;
    }

    /** Returns the shared supersampling canvas, enlarging it when needed. */
    private function supersampleCanvas(int $width, int $height): GdImage
    {
        if ($this->canvas === null || imagesx($this->canvas) < $width || imagesy($this->canvas) < $height) {
            $this->canvas = imagecreatetruecolor(max($width, $this->canvas ? imagesx($this->canvas) : 0), max($height, $this->canvas ? imagesy($this->canvas) : 0));
            imagefill($this->canvas, 0, 0, imagecolorallocate($this->canvas, 255, 255, 255));
        }

        return $this->canvas;
    }

    private function dividerBand(): GdImage
    {
        [$height, $thickness, $dash, $gap] = [20, 2, 8, 4];
        $band = $this->blank($this->width, $height);
        $ink = imagecolorallocate($band, 0, 0, 0);
        $top = intdiv($height - $thickness, 2);
        for ($x = $this->left; $x < $this->right; $x += $dash + $gap) {
            imagefilledrectangle($band, $x, $top, min($x + $dash, $this->right) - 1, $top + $thickness - 1, $ink);
        }

        return $band;
    }

    /** Draws a centered QR code. Each module spans a whole number of dots, which keeps edges sharp. */
    private function qrBand(string $data, int $size): GdImage
    {
        if ($data === '') {
            throw new RenderException('QR "data" must not be empty.');
        }
        try {
            $matrix = Encoder::encode($data, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        } catch (Throwable $e) {
            throw new RenderException('Could not make a QR code: '.$e->getMessage(), 0, $e);
        }
        $modules = $matrix->getWidth();
        $contentWidth = $this->right - $this->left;
        $dot = max(1, intdiv(min($size, $contentWidth), $modules));
        if ($modules * $dot > $contentWidth) {
            throw new RenderException("QR data is too long for a code {$contentWidth} dots wide.");
        }

        $padding = 8;
        $band = $this->blank($this->width, $modules * $dot + 2 * $padding);
        $ink = imagecolorallocate($band, 0, 0, 0);
        $x0 = $this->left + intdiv($contentWidth - $modules * $dot, 2);
        for ($my = 0; $my < $modules; $my++) {
            for ($mx = 0; $mx < $modules; $mx++) {
                if ($matrix->get($mx, $my) === 1) {
                    $x = $x0 + $mx * $dot;
                    $y = $padding + $my * $dot;
                    imagefilledrectangle($band, $x, $y, $x + $dot - 1, $y + $dot - 1, $ink);
                }
            }
        }

        return $band;
    }

    private function feedBand(mixed $lines): GdImage
    {
        if (! is_int($lines) || $lines < 1 || $lines > 50) {
            throw new RenderException('Lines must be a whole number from 1 to 50.');
        }

        return $this->blank($this->width, $lines * self::FEED_LINE);
    }

    private function blank(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, max(1, $height));
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));

        return $image;
    }

    /** @param  array<string, mixed>  $item */
    private static function string(array $item, string $field): string
    {
        $value = $item[$field] ?? '';
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new RenderException("\"{$field}\" must be text.");
        }

        return (string) $value;
    }
}
