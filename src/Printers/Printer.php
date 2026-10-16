<?php

declare(strict_types=1);

namespace RawanD201\KurdishEscpos\Printers;

use RawanD201\KurdishEscpos\Exceptions\PrinterException;

/** A destination for ESC/POS command streams. */
interface Printer
{
    /** @throws PrinterException */
    public function send(string $bytes): void;
}
