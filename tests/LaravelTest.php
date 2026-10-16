<?php

declare(strict_types=1);

namespace RawanD201\KurdishEscpos\Tests;

use Orchestra\Testbench\TestCase;
use RawanD201\KurdishEscpos\KurdishEscpos;
use RawanD201\KurdishEscpos\Laravel\Facades\KurdishEscpos as KurdishEscposFacade;
use RawanD201\KurdishEscpos\Laravel\KurdishEscposServiceProvider;
use RawanD201\KurdishEscpos\Printers\FilePrinter;
use RawanD201\KurdishEscpos\Printers\NetworkPrinter;
use RawanD201\KurdishEscpos\Renderer;

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

    public function test_printer_options_come_from_config(): void
    {
        config(['kurdish-escpos.printer.image' => 'column', 'kurdish-escpos.printer.cut' => 'none']);

        $escpos = $this->app->make(Renderer::class)->render(['items' => [['type' => 'text', 'text' => 'سوپاس']]])->escpos;

        $this->assertStringContainsString("\x1B\x2A\x21", $escpos);
        $this->assertStringNotContainsString("\x1D\x56", $escpos);
    }

    public function test_test_print_command(): void
    {
        $device = tempnam(sys_get_temp_dir(), 'kurdish-escpos-test-');
        $preview = $device.'.png';
        try {
            $this->artisan('kurdish-escpos:test-print', ['printer' => $device, '--preview' => $preview])
                ->assertSuccessful();
            $this->assertStringStartsWith("\x1B\x40\x1D\x76\x30\x00", (string) file_get_contents($device));
            $this->assertSame(576, getimagesize($preview)[0]);

            $this->artisan('kurdish-escpos:test-print', ['printer' => $device, '--width' => 384, '--image' => 'column'])
                ->assertSuccessful();
            $this->assertStringContainsString("\x1B\x2A\x21\x80\x01", (string) file_get_contents($device));

            $this->artisan('kurdish-escpos:test-print', ['printer' => $device, '--image' => 'bitmap'])
                ->assertFailed();
        } finally {
            @unlink($device);
            @unlink($preview);
        }
    }

    public function test_label_printers_from_config_and_test_print(): void
    {
        $device = (string) tempnam(sys_get_temp_dir(), 'kurdish-escpos-test-');
        config(['kurdish-escpos.printers' => [
            'default' => ['path' => $device, 'language' => 'tspl', 'width' => 400, 'label_height' => 240, 'darkness' => 8],
        ]]);

        try {
            KurdishEscposFacade::print(KurdishEscposFacade::receipt()->text('سوپاس'));
            $this->assertStringStartsWith("SIZE 50 mm,30 mm\nGAP 2 mm,0 mm\nDENSITY 8\nCLS\nBITMAP 0,0,50,", (string) file_get_contents($device));

            $this->artisan('kurdish-escpos:test-print', ['printer' => $device, '--language' => 'zpl', '--width' => 812])
                ->assertSuccessful();
            $this->assertStringStartsWith("^XA\n^PW812\n", (string) file_get_contents($device));

            $this->artisan('kurdish-escpos:test-print', ['printer' => $device, '--language' => 'epl'])
                ->assertFailed();
        } finally {
            @unlink($device);
        }
    }

    public function test_named_printers_from_config(): void
    {
        [$cashier, $kitchen] = [(string) tempnam(sys_get_temp_dir(), 'kurdish-escpos-test-'), (string) tempnam(sys_get_temp_dir(), 'kurdish-escpos-test-')];
        config([
            'kurdish-escpos.default' => 'cashier',
            'kurdish-escpos.printers' => [
                'cashier' => ['path' => $cashier],
                'kitchen' => ['path' => $kitchen, 'width' => 384, 'image' => 'column'],
                'unused' => ['host' => null],
            ],
        ]);

        try {
            $this->assertSame(['cashier', 'kitchen'], KurdishEscposFacade::printerNames());

            $result = KurdishEscposFacade::print(KurdishEscposFacade::receipt()->text('سوپاس'), ['cashier', 'kitchen']);
            $this->assertTrue($result->ok());
            $this->assertStringContainsString("\x1D\x76\x30\x00\x48\x00", (string) file_get_contents($cashier), 'raster, 576 dots');
            $this->assertStringContainsString("\x1B\x2A\x21\x80\x01", (string) file_get_contents($kitchen), 'column, 384 dots');

            file_put_contents($kitchen, '');
            KurdishEscposFacade::print(KurdishEscposFacade::receipt()->text('سوپاس'));
            $this->assertSame('', file_get_contents($kitchen), 'without a name, only the default printer prints');

            $this->artisan('kurdish-escpos:test-print', ['printer' => 'kitchen'])->assertSuccessful();
            $this->assertNotSame('', file_get_contents($kitchen));

            file_put_contents($cashier, '');
            file_put_contents($kitchen, '');
            $this->artisan('kurdish-escpos:test-print', ['--all' => true])->assertSuccessful();
            $this->assertNotSame('', file_get_contents($cashier));
            $this->assertNotSame('', file_get_contents($kitchen));
        } finally {
            @unlink($cashier);
            @unlink($kitchen);
        }
    }
}
