<?php

declare(strict_types=1);

namespace RawanD201\KurdishEscpos\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use RawanD201\KurdishEscpos\EscPos\PrinterOptions;
use RawanD201\KurdishEscpos\KurdishEscpos;
use RawanD201\KurdishEscpos\Printers\PrinterSetup;
use RawanD201\KurdishEscpos\Receipt;
use RawanD201\KurdishEscpos\Renderer;

class KurdishEscposServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/kurdish-escpos.php', 'kurdish-escpos');

        $this->app->singleton(Renderer::class, function (Application $app) {
            $config = (array) $app['config']->get('kurdish-escpos', []);
            $default = self::printerConfigs($config)[self::defaultName($config)] ?? [];

            return new Renderer(($config['font'] ?? null) ?: null, PrinterOptions::fromArray($default));
        });

        $this->app->singleton(KurdishEscpos::class, function (Application $app) {
            $config = (array) $app['config']->get('kurdish-escpos', []);
            $printers = [];
            foreach (self::printerConfigs($config) as $name => $printerConfig) {
                if ($setup = PrinterSetup::fromArray($printerConfig)) {
                    $printers[$name] = $setup;
                }
            }

            return new KurdishEscpos(
                $app->make(Renderer::class),
                $printers,
                (int) ($config['width'] ?? Receipt::WIDTH_80MM),
                $config['margin'] ?? null,
                self::defaultName($config),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/kurdish-escpos.php' => config_path('kurdish-escpos.php'),
            ], 'kurdish-escpos-config');

            $this->commands([Console\CheckFontCommand::class, Console\TestPrintCommand::class]);
        }
    }

    /**
     * The printers from the configuration. A configuration published before
     * version 0.3 has a single "printer" entry, which becomes "default".
     *
     * @param  array<string, mixed>  $config
     * @return array<string, array<string, mixed>>
     */
    private static function printerConfigs(array $config): array
    {
        if (isset($config['printer']) && is_array($config['printer'])) {
            return ['default' => $config['printer']];
        }
        $printers = [];
        foreach ((array) ($config['printers'] ?? []) as $name => $printer) {
            if (is_array($printer)) {
                $printers[(string) $name] = $printer;
            }
        }

        return $printers;
    }

    /** @param  array<string, mixed>  $config */
    private static function defaultName(array $config): string
    {
        return isset($config['printer']) ? 'default' : (string) ($config['default'] ?? 'default');
    }
}
