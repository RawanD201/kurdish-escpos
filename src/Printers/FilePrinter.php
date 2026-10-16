<?php

declare(strict_types=1);

namespace RawanD201\KurdishEscpos\Printers;

use RawanD201\KurdishEscpos\Exceptions\PrinterException;

/**
 * A printer exposed by the operating system as a file, such as a USB printer
 * on Linux (/dev/usb/lp0) or a shared printer on Windows
 * (\\localhost\XPrinter). The PHP process must have write access to it.
 */
final class FilePrinter implements Printer
{
    public function __construct(private readonly string $path) {}

    public function send(string $bytes): void
    {
        $stream = @fopen($this->path, 'wb');
        if ($stream === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new PrinterException("Could not open the printer at {$this->path}: {$error}");
        }

        try {
            StreamWriter::writeAll($stream, $bytes, $this->path);
        } finally {
            fclose($stream);
        }
    }
}
