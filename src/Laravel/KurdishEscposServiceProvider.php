<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Rawand\KurdishEscpos\KurdishEscpos;
use Rawand\KurdishEscpos\Printers\FilePrinter;
use Rawand\KurdishEscpos\Printers\NetworkPrinter;
use Rawand\KurdishEscpos\Printers\Printer;
use Rawand\KurdishEscpos\Receipt;
use Rawand\KurdishEscpos\Renderer;

class KurdishEscposServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/kurdish-escpos.php', 'kurdish-escpos');

        $this->app->singleton(Renderer::class, fn (Application $app) => new Renderer(
            $app['config']->get('kurdish-escpos.font') ?: null,
        ));

        $this->app->singleton(KurdishEscpos::class, fn (Application $app) => new KurdishEscpos(
            $app->make(Renderer::class),
            self::defaultPrinter($app['config']->get('kurdish-escpos.printer', [])),
            (int) $app['config']->get('kurdish-escpos.width', Receipt::WIDTH_80MM),
            $app['config']->get('kurdish-escpos.margin'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/kurdish-escpos.php' => config_path('kurdish-escpos.php'),
            ], 'kurdish-escpos-config');

            $this->commands([Console\CheckFontCommand::class]);
        }
    }

    /** @param  array{host?: ?string, port?: int, path?: ?string, timeout?: float}  $config */
    private static function defaultPrinter(array $config): ?Printer
    {
        if (! empty($config['host'])) {
            return new NetworkPrinter($config['host'], (int) ($config['port'] ?? 9100), (float) ($config['timeout'] ?? 10));
        }
        if (! empty($config['path'])) {
            return new FilePrinter($config['path']);
        }

        return null;
    }
}
