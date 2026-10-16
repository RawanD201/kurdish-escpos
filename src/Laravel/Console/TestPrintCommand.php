<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Laravel\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
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
                            {printer? : IP address (192.168.1.50 or 192.168.1.50:9100) or device path (/dev/usb/lp0); defaults to the configured printer}
                            {--width= : Printable width in dots, such as 576, 512 or 384}
                            {--image= : Picture command: raster, or column for older printers}
                            {--cut= : Cut: partial, full or none}
                            {--preview= : Also save the test page to this PNG file}';

    protected $description = 'Print a test page to check a receipt printer';

    public function handle(): int
    {
        $config = $this->laravel['config'];
        $printerConfig = $config->get('kurdish-escpos.printer', []);
        $width = (int) ($this->option('width') ?: $config->get('kurdish-escpos.width', Receipt::WIDTH_80MM));

        try {
            $options = PrinterOptions::fromArray([
                ...$printerConfig,
                'image' => $this->option('image') ?: ($printerConfig['image'] ?? PrinterOptions::IMAGE_RASTER),
                'cut' => $this->option('cut') ?: ($printerConfig['cut'] ?? PrinterOptions::CUT_PARTIAL),
            ]);
            $printer = $this->printer($printerConfig);
            $version = InstalledVersions::getPrettyVersion('rawand201/kurdish-escpos') ?? 'dev';
            $rendered = (new Renderer($config->get('kurdish-escpos.font') ?: null, $options))
                ->render(TestPage::receipt($width, "{$width} dots · {$options->image} · {$version}"));

            if ($preview = $this->option('preview')) {
                $rendered->savePng($preview);
                $this->components->info("Test page saved to {$preview}");
            }
            $printer->send($rendered->escpos);
        } catch (KurdishEscposException|InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Test page sent. If nothing printed, or the printer printed symbols, try --image=column.');

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $config */
    private function printer(array $config): Printer
    {
        $target = $this->argument('printer');
        if (! is_string($target) || $target === '') {
            $printer = $this->laravel->make(KurdishEscpos::class)->printer();

            return $printer ?? throw new InvalidArgumentException('No printer given and no default printer configured (set KURDISH_ESCPOS_PRINTER_HOST).');
        }
        if (str_starts_with($target, '/') || str_starts_with($target, '\\\\')) {
            return new FilePrinter($target);
        }
        $port = (int) ($config['port'] ?? 9100);
        if (preg_match('/^(.+):(\d+)$/', $target, $m) && ! str_contains($m[1], ':')) {
            [$target, $port] = [$m[1], (int) $m[2]];
        }

        return new NetworkPrinter($target, $port, (float) ($config['timeout'] ?? 10));
    }
}
