<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Printers;

use Rawand\KurdishEscpos\Exceptions\PrinterException;

/** A destination for ESC/POS command streams. */
interface Printer
{
    /** @throws PrinterException */
    public function send(string $bytes): void;
}
