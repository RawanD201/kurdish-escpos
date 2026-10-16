<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos;

use Rawand\KurdishEscpos\Exceptions\PrinterException;
use Rawand\KurdishEscpos\Printers\ParallelSender;
use Rawand\KurdishEscpos\Printers\Printer;
use Rawand\KurdishEscpos\Printers\PrinterSetup;
use Rawand\KurdishEscpos\Text\Font;

/**
 * Main entry point. Creates receipts, renders them, and sends them to one or
 * more printers.
 *
 *     $escpos = new KurdishEscpos(new Renderer, [
 *         'cashier' => new NetworkPrinter('192.168.1.50'),
 *         'kitchen' => new PrinterSetup(new NetworkPrinter('192.168.1.51'), width: 384),
 *     ], default: 'cashier');
 *
 *     $escpos->print($receipt);                          // the default printer
 *     $escpos->print($receipt, 'kitchen');               // one named printer
 *     $escpos->print($receipt, ['cashier', 'kitchen']);  // several at once
 */
class KurdishEscpos
{
    /** @var array<string, PrinterSetup> */
    private array $printers = [];

    /** @var array<string, Renderer> */
    private array $renderers = [];

    /**
     * @param  Printer|array<string, Printer|PrinterSetup>|null  $printers  One printer, or printers by name.
     * @param  string  $default  The name of the printer used when none is given.
     */
    public function __construct(
        private readonly Renderer $renderer,
        Printer|array|null $printers = null,
        private readonly int $width = Receipt::WIDTH_80MM,
        private readonly ?int $margin = null,
        private readonly string $default = 'default',
    ) {
        if ($printers instanceof Printer) {
            $printers = [$default => $printers];
        }
        foreach ($printers ?? [] as $name => $printer) {
            $this->printers[(string) $name] = $printer instanceof PrinterSetup ? $printer : new PrinterSetup($printer);
        }
    }

    /** Creates an empty receipt with the width of the named printer, or the configured width. */
    public function receipt(?string $printer = null): Receipt
    {
        $width = $printer === null ? $this->width : ($this->setup($printer)->width ?? $this->width);

        return new Receipt($width, $this->margin);
    }

    /**
     * Renders a receipt, laid out for the named printer when one is given.
     *
     * @param  Receipt|array<string, mixed>|string  $receipt
     */
    public function render(Receipt|array|string $receipt, ?string $printer = null): RenderedReceipt
    {
        return $printer === null ? $this->renderer->render($receipt) : $this->renderFor($this->setup($printer), Renderer::parse($receipt));
    }

    /**
     * Prints a receipt.
     *
     * - Without a printer, it goes to the default printer.
     * - With a printer name or a Printer object, it goes to that printer, and
     *   a failure throws a PrinterException.
     * - With a list, it goes to all of those printers at the same time. A
     *   printer that fails does not stop the others; check the result, or call
     *   throw() on it.
     *
     * Each printer gets the receipt laid out for its own width and settings.
     *
     * @param  Receipt|array<string, mixed>|string  $receipt
     * @param  Printer|string|array<array-key, Printer|string>|null  $to
     *
     * @throws PrinterException
     */
    public function print(Receipt|array|string $receipt, Printer|string|array|null $to = null): PrintResult
    {
        $many = is_array($to);
        $targets = [];
        foreach ($many ? $to : [$to] as $key => $target) {
            if ($target instanceof Printer) {
                $targets[is_string($key) ? $key : 'printer '.($key + 1)] = new PrinterSetup($target);
            } else {
                $name = $target ?? $this->default;
                $targets[$name] = $this->setup($name);
            }
        }
        $data = Renderer::parse($receipt);
        $result = $this->send(array_map(fn (PrinterSetup $setup) => [$setup, $data], $targets));

        return $many ? $result : $result->throw();
    }

    /**
     * Prints different receipts on different printers at the same time, for
     * example the customer receipt at the cashier and the order ticket in the
     * kitchen. A printer that fails does not stop the others.
     *
     *     $escpos->printEach(['cashier' => $receipt, 'kitchen' => $ticket]);
     *
     * @param  array<string, Receipt|array<string, mixed>|string>  $receipts  printer name => receipt
     */
    public function printEach(array $receipts): PrintResult
    {
        $targets = [];
        foreach ($receipts as $name => $receipt) {
            $targets[(string) $name] = [$this->setup((string) $name), Renderer::parse($receipt)];
        }

        return $this->send($targets);
    }

    public function renderer(): Renderer
    {
        return $this->renderer;
    }

    /** The named printer, or the default printer. Null if it is not configured. */
    public function printer(?string $name = null): ?Printer
    {
        return ($this->printers[$name ?? $this->default] ?? null)?->printer;
    }

    /** The name of the printer used when none is given. */
    public function defaultPrinterName(): string
    {
        return $this->default;
    }

    /** @return list<string> the names of the configured printers */
    public function printerNames(): array
    {
        return array_keys($this->printers);
    }

    /**
     * Renders each receipt for its printer and sends them all at once.
     * Identical receipts for printers with the same settings are rendered once.
     *
     * @param  array<string, array{PrinterSetup, array<string, mixed>}>  $targets
     */
    private function send(array $targets): PrintResult
    {
        if ($targets === []) {
            throw new PrinterException('No printers given.');
        }
        $rendered = $jobs = $cache = [];
        foreach ($targets as $name => [$setup, $data]) {
            $key = spl_object_id($this->rendererFor($setup)).':'.($setup->width ?? '').':'.md5(serialize($data));
            $rendered[$name] = $cache[$key] ??= $this->renderFor($setup, $data);
            $jobs[$name] = [$setup->printer, $rendered[$name]->commands()];
        }

        return new PrintResult($rendered, array_filter(ParallelSender::send($jobs)));
    }

    private function setup(string $name): PrinterSetup
    {
        if (isset($this->printers[$name])) {
            return $this->printers[$name];
        }
        if ($this->printers === []) {
            throw new PrinterException('No printer given and no printer configured (set KURDISH_ESCPOS_PRINTER_HOST).');
        }

        throw new PrinterException("Unknown printer \"{$name}\". Configured printers: ".implode(', ', array_keys($this->printers)).'.');
    }

    /** @param  array<string, mixed>  $data */
    private function renderFor(PrinterSetup $setup, array $data): RenderedReceipt
    {
        if ($setup->width !== null) {
            $data['width'] = $setup->width;
        }

        return $this->rendererFor($setup)->render($data);
    }

    /** A renderer with the printer's font and options; printers with the same settings share one. */
    private function rendererFor(PrinterSetup $setup): Renderer
    {
        $font = $setup->font ?? $this->renderer->font();
        $options = $setup->options ?? $this->renderer->options();
        // Options hold only scalar settings, so == compares every one of them.
        if ($font === $this->renderer->font() && $options == $this->renderer->options()) {
            return $this->renderer;
        }
        $key = ($font instanceof Font ? 'font:'.spl_object_id($font) : 'path:'.$font).'|'.serialize($options);

        return $this->renderers[$key] ??= new Renderer($font, $options);
    }
}
