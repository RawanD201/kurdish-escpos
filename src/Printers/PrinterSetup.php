<?php

declare(strict_types=1);

namespace RawanD201\KurdishEscpos\Printers;

use RawanD201\KurdishEscpos\EscPos\PrinterOptions;
use RawanD201\KurdishEscpos\Text\Font;

/**
 * A printer together with its own settings. Settings that are null fall
 * back to the defaults of the KurdishEscpos instance.
 */
final class PrinterSetup
{
    /**
     * @param  int|null  $width  Printable width in dots; the receipt is laid out for this width.
     * @param  PrinterOptions|null  $options  Printer language, image command, cut, feed and label settings.
     * @param  Font|string|null  $font  A font, or the path to a .ttf file.
     */
    public function __construct(
        public readonly Printer $printer,
        public readonly ?int $width = null,
        public readonly ?PrinterOptions $options = null,
        public readonly Font|string|null $font = null,
    ) {}

    /**
     * Creates a setup from a configuration array. "host" (with "port" and
     * "timeout") selects a network printer and "path" a file printer;
     * "width", "font", "image", "cut", "feed" and "drawer_pin" are optional.
     * Returns null when neither "host" nor "path" is set.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): ?self
    {
        if (! empty($config['host'])) {
            $printer = new NetworkPrinter((string) $config['host'], (int) ($config['port'] ?? 9100), (float) ($config['timeout'] ?? 10));
        } elseif (! empty($config['path'])) {
            $printer = new FilePrinter((string) $config['path']);
        } else {
            return null;
        }

        return new self(
            $printer,
            isset($config['width']) ? (int) $config['width'] : null,
            PrinterOptions::fromArray($config),
            empty($config['font']) ? null : (string) $config['font'],
        );
    }
}
