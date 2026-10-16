<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos;

use Rawand\KurdishEscpos\Exceptions\PrinterException;
use Rawand\KurdishEscpos\Printers\Printer;

/**
 * Main entry point. Creates receipts with the configured paper width,
 * renders them, and sends them to a printer.
 *
 *     $escpos = new KurdishEscpos(new Renderer, new NetworkPrinter('192.168.1.50'));
 *     $escpos->print($escpos->receipt()->title('دەرمانخانەی ئازادی')->row('ڤیتامین', '5,000'));
 */
class KurdishEscpos
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly ?Printer $printer = null,
        private readonly int $width = Receipt::WIDTH_80MM,
        private readonly ?int $margin = null,
    ) {}

    /** Creates an empty receipt with the configured width and margin. */
    public function receipt(): Receipt
    {
        return new Receipt($this->width, $this->margin);
    }

    /**
     * @param  Receipt|array<string, mixed>|string  $receipt
     */
    public function render(Receipt|array|string $receipt): RenderedReceipt
    {
        return $this->renderer->render($receipt);
    }

    /**
     * Renders the receipt and sends it to the given printer, or to the
     * default printer when none is given.
     *
     * @param  Receipt|array<string, mixed>|string  $receipt
     */
    public function print(Receipt|array|string $receipt, ?Printer $printer = null): RenderedReceipt
    {
        $printer ??= $this->printer ?? throw new PrinterException(
            'No printer given and no default printer configured (set KURDISH_ESCPOS_PRINTER_HOST).'
        );
        $rendered = $this->render($receipt);
        $printer->send($rendered->escpos);

        return $rendered;
    }

    public function renderer(): Renderer
    {
        return $this->renderer;
    }

    public function printer(): ?Printer
    {
        return $this->printer;
    }
}
