<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\EscPos;

use InvalidArgumentException;

/**
 * Printer settings that affect the ESC/POS command stream rather than the
 * receipt's content.
 */
final class PrinterOptions
{
    /** The standard raster image command (GS v 0), supported by most printers. */
    public const IMAGE_RASTER = 'raster';

    /** The older bit-image command (ESC *), for printers without GS v 0. */
    public const IMAGE_COLUMN = 'column';

    public const CUT_PARTIAL = 'partial';

    public const CUT_FULL = 'full';

    public const CUT_NONE = 'none';

    /**
     * @param  string  $image  "raster" (default) or "column" for older printers.
     * @param  string  $cut  "partial" (default), "full", or "none" for printers without a cutter.
     * @param  int  $feed  Lines of paper fed after the receipt, before the cut.
     * @param  int  $drawerPin  Connector pin of the cash drawer: 2 (default) or 5.
     */
    public function __construct(
        public readonly string $image = self::IMAGE_RASTER,
        public readonly string $cut = self::CUT_PARTIAL,
        public readonly int $feed = 4,
        public readonly int $drawerPin = 2,
    ) {
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

    /**
     * Creates options from a configuration array with the keys "image", "cut",
     * "feed" and "drawer_pin". Missing keys use the defaults.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            (string) ($config['image'] ?? self::IMAGE_RASTER),
            (string) ($config['cut'] ?? self::CUT_PARTIAL),
            (int) ($config['feed'] ?? 4),
            (int) ($config['drawer_pin'] ?? 2),
        );
    }
}
