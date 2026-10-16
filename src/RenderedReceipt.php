<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos;

/**
 * The result of rendering a receipt: a PNG preview and the command stream
 * for the printer. Both contain exactly the same dots.
 *
 * The $escpos property keeps its name for compatibility; for a Zebra or TSC
 * printer it holds ZPL or TSPL commands instead of ESC/POS.
 */
final class RenderedReceipt
{
    public function __construct(
        public readonly string $png,
        public readonly string $escpos,
    ) {}

    /** The printer commands, in the language set in the printer options. */
    public function commands(): string
    {
        return $this->escpos;
    }

    /** Returns the preview as a data URI, suitable for an HTML img element. */
    public function pngDataUri(): string
    {
        return 'data:image/png;base64,'.base64_encode($this->png);
    }

    public function savePng(string $path): void
    {
        self::write($path, $this->png);
    }

    public function saveEscpos(string $path): void
    {
        self::write($path, $this->escpos);
    }

    private static function write(string $path, string $bytes): void
    {
        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new Exceptions\KurdishEscposException("Could not write {$path}.");
        }
    }
}
