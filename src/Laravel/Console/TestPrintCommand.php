<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Laravel\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Rawand\KurdishEscpos\EscPos\PrinterOptions;
use Rawand\KurdishEscpos\Exceptions\KurdishEscposException;
use Rawand\KurdishEscpos\KurdishEscpos;
use Rawand\KurdishEscpos\Printers\FilePrinter;
use Rawand\KurdishEscpos\Printers\NetworkPrinter;
use Rawand\KurdishEscpos\Printers\Printer;
use Rawand\KurdishEscpos\Receipt;
use Rawand\KurdishEscpos\Renderer;
use Rawand\KurdishEscpos\TestPage;

class TestPrintCommand extends Command
{
    protected $signature = 'kurdish-escpos:test-print
                            {printer? : A configured printer name, an IP address (192.168.1.50 or 192.168.1.50:9100), or a device path (/dev/usb/lp0)}
                            {--all : Print the test page on every configured printer at once}
                            {--width= : Printable width in dots, such as 576, 512 or 384 (for an address or path)}
                            {--language= : Printer language: escpos, zpl for Zebra or tspl for TSC (for an address or path)}
                            {--image= : Picture command: raster, or column for older printers (for an address or path)}
                            {--cut= : Cut: partial, full or none (for an address or path)}
                            {--preview= : Also save the test page to this PNG file}';

    protected $description = 'Print a test page to check one or more receipt or label printers';

    public function handle(KurdishEscpos $escpos, Repository $config): int
    {
        $target = $this->argument('printer');

        try {
            if ($this->option('all')) {
                return $this->printNamed($escpos, $escpos->printerNames());
            }
            if (! is_string($target) || $target === '') {
                return $this->printNamed($escpos, [$escpos->defaultPrinterName()]);
            }
            if (in_array($target, $escpos->printerNames(), true)) {
                return $this->printNamed($escpos, [$target]);
            }

            return $this->printAddress($target, $config);
        } catch (KurdishEscposException|InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Prints on configured printers, all at the same time.
     *
     * @param  list<string>  $names
     */
    private function printNamed(KurdishEscpos $escpos, array $names): int
    {
        if ($names === []) {
            throw new InvalidArgumentException('No printers are configured.');
        }
        // The test page shows each printer's name, so each gets its own page;
        // all pages are still sent at the same time.
        $receipts = [];
        foreach ($names as $name) {
            $width = $escpos->receipt($name)->toArray()['width'];
            $receipts[$name] = TestPage::receipt($width, "{$name} · {$width} dots · ".self::version());
        }
        $result = count($names) === 1
            ? $escpos->print($receipts[$names[0]], [$names[0]])
            : $escpos->printEach($receipts);

        foreach ($result->printed() as $printed) {
            $this->components->info("Test page sent to {$printed}.");
        }
        foreach ($result->failures() as $printer => $error) {
            $this->components->error("{$printer}: {$error->getMessage()}");
        }
        $this->savePreview($result->rendered()->png);

        return $result->ok() ? self::SUCCESS : self::FAILURE;
    }

    private function printAddress(string $target, Repository $config): int
    {
        $width = (int) ($this->option('width') ?: $config->get('kurdish-escpos.width', Receipt::WIDTH_80MM));
        $options = new PrinterOptions(
            image: $this->option('image') ?: PrinterOptions::IMAGE_RASTER,
            cut: $this->option('cut') ?: PrinterOptions::CUT_PARTIAL,
            language: $this->option('language') ?: PrinterOptions::LANGUAGE_ESCPOS,
        );
        $mode = $options->isLabel() ? $options->language : $options->image;
        $rendered = (new Renderer($config->get('kurdish-escpos.font') ?: null, $options))
            ->render(TestPage::receipt($width, "{$width} dots · {$mode} · ".self::version()));

        $this->savePreview($rendered->png);
        $this->printer($target)->send($rendered->commands());
        $this->components->info($options->isLabel()
            ? 'Test page sent. If it is cut off, set --width to the label width in dots.'
            : 'Test page sent. If nothing printed, or the printer printed symbols, try --image=column.');

        return self::SUCCESS;
    }

    private function printer(string $target): Printer
    {
        if (str_starts_with($target, '/') || str_starts_with($target, '\\\\')) {
            return new FilePrinter($target);
        }
        $port = 9100;
        if (preg_match('/^(.+):(\d+)$/', $target, $m) && ! str_contains($m[1], ':')) {
            [$target, $port] = [$m[1], (int) $m[2]];
        }

        return new NetworkPrinter($target, $port);
    }

    private function savePreview(string $png): void
    {
        if ($preview = $this->option('preview')) {
            file_put_contents($preview, $png);
            $this->components->info("Test page saved to {$preview}");
        }
    }

    private static function version(): string
    {
        return InstalledVersions::getPrettyVersion('rawand201/kurdish-escpos') ?? 'dev';
    }
}
