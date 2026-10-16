<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Tests;

use PHPUnit\Framework\TestCase;
use Rawand\KurdishEscpos\EscPos\Bitmap;
use Rawand\KurdishEscpos\EscPos\PrinterOptions;
use Rawand\KurdishEscpos\Exceptions\RenderException;
use Rawand\KurdishEscpos\Receipt;
use Rawand\KurdishEscpos\Renderer;

final class RendererTest extends TestCase
{
    private static ?Renderer $renderer = null;

    private static function renderer(): Renderer
    {
        return self::$renderer ??= new Renderer;
    }

    public function test_renders_the_example_receipt(): void
    {
        $rendered = self::renderer()->render((string) file_get_contents(__DIR__.'/fixtures/receipt.json'));

        [$width, $height] = getimagesizefromstring($rendered->png) ?: [0, 0];
        $this->assertSame(576, $width);
        $this->assertGreaterThan(500, $height);

        $this->assertStringStartsWith(Bitmap::INIT.Bitmap::RASTER."\x48\x00\x00\x01", $rendered->escpos, '72 bytes x 256 rows in the first band');
        $this->assertStringEndsWith(Bitmap::FEED.Bitmap::CUT, $rendered->escpos);
        $this->assertStringStartsWith('data:image/png;base64,', $rendered->pngDataUri());
    }

    /** The printer bytes and the PNG preview must show exactly the same dots. */
    public function test_printer_bytes_match_the_preview(): void
    {
        $rendered = self::renderer()->render(
            Receipt::make()->title('کوڵێرە')->row('هەڵەبجە', '2,000')->divider()->qr('https://example.com')->feed()
        );

        $fromBytes = Bitmap::fromEscPos($rendered->escpos);
        $preview = imagecreatefromstring($rendered->png);
        $this->assertSame([imagesx($preview), imagesy($preview)], [$fromBytes->width, $fromBytes->height]);
        $this->assertSame(Bitmap::fromImage($preview)->rows, $fromBytes->rows);
        $this->assertNotSame(str_repeat("\0", strlen($fromBytes->rows)), $fromBytes->rows, 'something is printed');
    }

    public function test_title_is_centered_and_kurdish_text_is_right_aligned(): void
    {
        $image = self::renderer()->draw(Receipt::make()->title('سوپاس')->text('سلێمانی'));
        $bitmap = Bitmap::fromImage($image);
        $bandHeight = intdiv($bitmap->height, 2);

        [$left, $right] = self::inkSpan($bitmap, 0, $bandHeight);
        $this->assertEqualsWithDelta(576 - $right, $left, 4, 'title is centered');

        [, $right] = self::inkSpan($bitmap, $bandHeight, $bitmap->height);
        $this->assertGreaterThan(576 - 8 - 6, $right, 'Kurdish text starts at the right margin');
    }

    /** The name starts the line in its own direction; the price is at the other end. */
    public function test_rows_follow_the_direction_of_the_name(): void
    {
        $ink = function (string $name): array {
            $bitmap = Bitmap::fromImage(self::renderer()->draw(['items' => [['type' => 'row', 'name' => $name, 'price' => '']]]));

            return self::inkSpan($bitmap, 0, $bitmap->height);
        };

        [$left] = $ink('Nan');
        $this->assertLessThan(20, $left, 'a Latin name starts at the left margin');

        [, $right] = $ink('نان');
        $this->assertGreaterThan(576 - 20, $right, 'a Kurdish name starts at the right margin');
    }

    public function test_58mm_paper(): void
    {
        $png = self::renderer()->render(Receipt::make(Receipt::WIDTH_58MM)->text('پێنج'))->png;

        $this->assertSame(384, getimagesizefromstring($png)[0]);
    }

    public function test_builder_array_and_json_give_the_same_bytes(): void
    {
        $receipt = Receipt::make()->text('ڕۆژێکی خۆش')->row('دینار', '5');

        $bytes = self::renderer()->render($receipt)->escpos;
        $this->assertSame($bytes, self::renderer()->render($receipt->toArray())->escpos);
        $this->assertSame($bytes, self::renderer()->render($receipt->toJson())->escpos);
    }

    public function test_reports_bad_input(): void
    {
        foreach ([
            '{"items":[]}' => 'no items',
            '{"items":[{"type":"banner"}]}' => 'Item 0 (banner): Unknown type "banner"',
            '{"items":[{"type":"text","text":"x","align":"left"}]}' => 'Unknown align "left"',
            '{"items":[{"type":"text","txt":"x"}]}' => 'Unknown field "txt"',
            '{"items":[{"type":"qr"}]}' => 'must not be empty',
            '{"width":-5,"items":[{"type":"feed"}]}' => 'Width must be',
            '{"width":"576","items":[{"type":"feed"}]}' => 'Width must be',
            '{"items":[{"type":"feed","lines":0}]}' => 'Lines must be',
            '{"colour":"red","items":[]}' => 'Unknown receipt field "colour"',
            '{"items":' => 'Invalid receipt JSON',
        ] as $json => $message) {
            try {
                self::renderer()->render($json);
                $this->fail("{$json} was accepted");
            } catch (RenderException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $json);
            }
        }
    }

    /** @return array{int, int} leftmost and rightmost black dot in rows [$from, $to) */
    private static function inkSpan(Bitmap $bitmap, int $from, int $to): array
    {
        [$left, $right] = [PHP_INT_MAX, -1];
        for ($y = $from; $y < $to; $y++) {
            for ($x = 0; $x < $bitmap->width; $x++) {
                if ($bitmap->isBlack($x, $y)) {
                    [$left, $right] = [min($left, $x), max($right, $x)];
                }
            }
        }

        return [$left, $right];
    }

    public function test_printer_options_and_cash_drawer(): void
    {
        $receipt = Receipt::make()->text('سوپاس')->openDrawer();

        $raster = self::renderer()->render($receipt)->escpos;
        $this->assertStringContainsString(Bitmap::RASTER, $raster);
        $this->assertStringEndsWith("\x1B\x70\x00\x19\xFA", $raster);

        $column = (new Renderer(null, new PrinterOptions(image: 'column', cut: 'none')))->render($receipt)->escpos;
        $this->assertStringContainsString(Bitmap::COLUMN, $column);
        $this->assertStringNotContainsString(Bitmap::CUT, $column);

        $this->expectException(RenderException::class);
        self::renderer()->render(['drawer' => 'yes', 'items' => [['type' => 'feed']]]);
    }
}
