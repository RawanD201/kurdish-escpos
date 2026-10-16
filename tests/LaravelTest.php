<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Tests;

use Orchestra\Testbench\TestCase;
use Rawand\KurdishEscpos\KurdishEscpos;
use Rawand\KurdishEscpos\Laravel\Facades\KurdishEscpos as KurdishEscposFacade;
use Rawand\KurdishEscpos\Laravel\KurdishEscposServiceProvider;
use Rawand\KurdishEscpos\Printers\FilePrinter;
use Rawand\KurdishEscpos\Printers\NetworkPrinter;
use Rawand\KurdishEscpos\Renderer;

final class LaravelTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [KurdishEscposServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['KurdishEscpos' => KurdishEscposFacade::class];
    }

    public function test_facade_builds_receipts_with_the_configured_width(): void
    {
        config(['kurdish-escpos.width' => 384]);

        $receipt = KurdishEscposFacade::receipt()->text('سوپاس');

        $this->assertSame(384, $receipt->toArray()['width']);
    }

    public function test_default_printer_comes_from_config(): void
    {
        config(['kurdish-escpos.printer.host' => '192.168.1.50']);
        $this->assertInstanceOf(NetworkPrinter::class, $this->app->make(KurdishEscpos::class)->printer());

        $this->app->forgetInstance(KurdishEscpos::class);
        config(['kurdish-escpos.printer.host' => null, 'kurdish-escpos.printer.path' => '/dev/usb/lp0']);
        $this->assertInstanceOf(FilePrinter::class, $this->app->make(KurdishEscpos::class)->printer());

        $this->app->forgetInstance(KurdishEscpos::class);
        config(['kurdish-escpos.printer.path' => null]);
        $this->assertNull($this->app->make(KurdishEscpos::class)->printer());
    }

    public function test_configured_font_is_used(): void
    {
        $receipt = ['items' => [['type' => 'text', 'text' => 'کوڵێرە']]];
        $default = $this->app->make(Renderer::class)->render($receipt)->escpos;

        $this->app->forgetInstance(Renderer::class);
        config(['kurdish-escpos.font' => __DIR__.'/fixtures/NotoNaskhArabic-Subset.ttf']);

        $this->assertNotSame($default, $this->app->make(Renderer::class)->render($receipt)->escpos);
    }

    public function test_check_font_command(): void
    {
        $this->artisan('kurdish-escpos:check-font', ['font' => __DIR__.'/fixtures/NotoNaskhArabic-Subset.ttf'])
            ->assertSuccessful();
        $this->artisan('kurdish-escpos:check-font', ['font' => __FILE__])
            ->assertFailed();
    }
}
