<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Printers;

use Rawand\KurdishEscpos\Exceptions\PrinterException;

/** @internal */
final class StreamWriter
{
    /**
     * Writes the whole string. fwrite() on a socket or device may perform a
     * partial write, so the call is repeated until all bytes are written.
     *
     * @param  resource  $stream
     */
    public static function writeAll($stream, string $bytes, string $target): void
    {
        $length = strlen($bytes);
        for ($written = 0; $written < $length; $written += $n) {
            $n = @fwrite($stream, substr($bytes, $written, 65536));
            if ($n === false || $n === 0 || stream_get_meta_data($stream)['timed_out']) {
                throw new PrinterException("Sending to {$target} stopped after {$written} of {$length} bytes.");
            }
        }
    }
}
