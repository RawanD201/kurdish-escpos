<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos;

use Rawand\KurdishEscpos\Exceptions\PrinterException;
use Throwable;

/**
 * The outcome of printing a receipt on one or more printers.
 */
final class PrintResult
{
    /**
     * @param  array<string, RenderedReceipt>  $rendered  printer name => what was sent to it
     * @param  array<string, Throwable>  $failures  printer name => error
     */
    public function __construct(
        private readonly array $rendered,
        private readonly array $failures = [],
    ) {}

    /** True when every printer received the receipt. */
    public function ok(): bool
    {
        return $this->failures === [];
    }

    /** @return list<string> names of the printers that received the receipt */
    public function printed(): array
    {
        return array_values(array_diff(array_keys($this->rendered), array_keys($this->failures)));
    }

    /** @return array<string, Throwable> printer name => error */
    public function failures(): array
    {
        return $this->failures;
    }

    /** The receipt as rendered for one printer, or for the first printer. */
    public function rendered(?string $printer = null): RenderedReceipt
    {
        if ($printer === null) {
            return $this->rendered[array_key_first($this->rendered)];
        }

        return $this->rendered[$printer] ?? throw new PrinterException("No receipt was rendered for the printer \"{$printer}\".");
    }

    /**
     * Throws a PrinterException if any printer failed.
     *
     * @throws PrinterException
     */
    public function throw(): self
    {
        if ($this->failures === []) {
            return $this;
        }
        $messages = [];
        foreach ($this->failures as $name => $error) {
            $messages[] = "{$name}: {$error->getMessage()}";
        }
        $first = $this->failures[array_key_first($this->failures)];

        throw new PrinterException(
            count($this->failures) === 1 && count($this->rendered) === 1 ? $first->getMessage() : 'Printing failed on '.implode('; ', $messages),
            0,
            $first,
        );
    }
}
