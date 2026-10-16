<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\EscPos;

use InvalidArgumentException;

/**
 * Printer settings that affect the command stream rather than the receipt's
 * content: the printer language, the picture command, cutting and labels.
 */
final class PrinterOptions
{
    /** Receipt printers (Epson, Xprinter and compatible). */
    public const LANGUAGE_ESCPOS = 'escpos';

    /** Zebra label printers. */
    public const LANGUAGE_ZPL = 'zpl';

    /** TSC label printers. */
    public const LANGUAGE_TSPL = 'tspl';

    /** The standard raster image command (GS v 0), supported by most printers. */
    public const IMAGE_RASTER = 'raster';

    /** The older bit-image command (ESC *), for printers without GS v 0. */
    public const IMAGE_COLUMN = 'column';

    public const CUT_PARTIAL = 'partial';

    public const CUT_FULL = 'full';

    public const CUT_NONE = 'none';

    /**
     * @param  string  $image  ESC/POS: "raster" (default) or "column" for older printers.
     * @param  string  $cut  ESC/POS: "partial" (default), "full", or "none" for printers without a cutter.
     * @param  int  $feed  ESC/POS: lines of paper fed after the receipt, before the cut.
     * @param  int  $drawerPin  ESC/POS: connector pin of the cash drawer, 2 (default) or 5.
     * @param  string  $language  "escpos" (default), "zpl" for Zebra or "tspl" for TSC printers.
     * @param  int|null  $labelHeight  Labels: label length in dots; null fits the content.
     * @param  float  $labelGap  TSC: gap between labels in mm, used when $labelHeight is set.
     * @param  int|null  $darkness  Labels: 0-30 for Zebra, 0-15 for TSC; null keeps the printer's setting.
     * @param  int  $dpi  Labels: printer resolution, 203 (default), 300 or 600; TSC needs it to convert dots to mm.
     */
    public function __construct(
        public readonly string $image = self::IMAGE_RASTER,
        public readonly string $cut = self::CUT_PARTIAL,
        public readonly int $feed = 4,
        public readonly int $drawerPin = 2,
        public readonly string $language = self::LANGUAGE_ESCPOS,
        public readonly ?int $labelHeight = null,
        public readonly float $labelGap = 2.0,
        public readonly ?int $darkness = null,
        public readonly int $dpi = 203,
    ) {
        if (! in_array($language, [self::LANGUAGE_ESCPOS, self::LANGUAGE_ZPL, self::LANGUAGE_TSPL], true)) {
            throw new InvalidArgumentException("Language must be \"escpos\", \"zpl\" or \"tspl\", got \"{$language}\".");
        }
        if ($labelHeight !== null && ($labelHeight < 1 || $labelHeight > 32000)) {
            throw new InvalidArgumentException("Label height must be from 1 to 32000 dots, got {$labelHeight}.");
        }
        if ($labelGap < 0 || $labelGap > 50) {
            throw new InvalidArgumentException("Label gap must be from 0 to 50 mm, got {$labelGap}.");
        }
        $maxDarkness = $language === self::LANGUAGE_TSPL ? 15 : 30;
        if ($darkness !== null && ($darkness < 0 || $darkness > $maxDarkness)) {
            throw new InvalidArgumentException("Darkness must be from 0 to {$maxDarkness}, got {$darkness}.");
        }
        if (! in_array($dpi, [203, 300, 600], true)) {
            throw new InvalidArgumentException("DPI must be 203, 300 or 600, got {$dpi}.");
        }
        if (! in_array($image, [self::IMAGE_RASTER, self::IMAGE_COLUMN], true)) {
            throw new InvalidArgumentException("Image must be \"raster\" or \"column\", got \"{$image}\".");
        }
        if (! in_array($cut, [self::CUT_PARTIAL, self::CUT_FULL, self::CUT_NONE], true)) {
            throw new InvalidArgumentException("Cut must be \"partial\", \"full\" or \"none\", got \"{$cut}\".");
        }
        if ($feed < 0 || $feed > 255) {
            throw new InvalidArgumentException("Feed must be from 0 to 255 lines, got {$feed}.");
        }
        if ($drawerPin !== 2 && $drawerPin !== 5) {
            throw new InvalidArgumentException("Drawer pin must be 2 or 5, got {$drawerPin}.");
        }
    }

    /** True for label printer languages (ZPL and TSPL). */
    public function isLabel(): bool
    {
        return $this->language !== self::LANGUAGE_ESCPOS;
    }

    /**
     * Creates options from a configuration array with the keys "image", "cut",
     * "feed", "drawer_pin", "language", "label_height", "label_gap",
     * "darkness" and "dpi". Missing keys, null and empty strings (an empty
     * .env value) use the defaults.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $config = array_filter($config, fn ($value) => $value !== null && $value !== '');

        return new self(
            (string) ($config['image'] ?? self::IMAGE_RASTER),
            (string) ($config['cut'] ?? self::CUT_PARTIAL),
            (int) ($config['feed'] ?? 4),
            (int) ($config['drawer_pin'] ?? 2),
            (string) ($config['language'] ?? self::LANGUAGE_ESCPOS),
            isset($config['label_height']) ? (int) $config['label_height'] : null,
            (float) ($config['label_gap'] ?? 2.0),
            isset($config['darkness']) ? (int) $config['darkness'] : null,
            (int) ($config['dpi'] ?? 203),
        );
    }
}
