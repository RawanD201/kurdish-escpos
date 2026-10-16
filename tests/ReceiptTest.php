<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rawand\KurdishEscpos\Receipt;

final class ReceiptTest extends TestCase
{
    public function test_builds_the_renderer_json(): void
    {
        $receipt = Receipt::make()
            ->title('دەرمانخانەی ئازادی')
            ->text('سلێمانی، شەقامی سالم', 24)
            ->divider()
            ->row('کوڵێرە', '1,250')
            ->qr('https://example.com/r/1')
            ->feed(2);

        $this->assertSame([
            'width' => 576,
            'items' => [
                ['type' => 'text', 'text' => 'دەرمانخانەی ئازادی', 'size' => 40.0, 'align' => 'center'],
                ['type' => 'text', 'text' => 'سلێمانی، شەقامی سالم', 'size' => 24.0, 'align' => 'start'],
                ['type' => 'divider'],
                ['type' => 'row', 'name' => 'کوڵێرە', 'price' => '1,250'],
                ['type' => 'qr', 'data' => 'https://example.com/r/1'],
                ['type' => 'feed', 'lines' => 2],
            ],
        ], $receipt->toArray());
    }

    public function test_json_keeps_kurdish_letters_unescaped(): void
    {
        $json = Receipt::make(Receipt::WIDTH_58MM, 4)->text('ڕۆژێکی خۆش')->toJson();

        $this->assertSame(
            '{"width":384,"margin":4,"items":[{"type":"text","text":"ڕۆژێکی خۆش","align":"start"}]}',
            $json,
        );
        $this->assertSame($json, json_encode(Receipt::make(384, 4)->text('ڕۆژێکی خۆش'), JSON_UNESCAPED_UNICODE));
    }

    public function test_rejects_unknown_alignment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Receipt::make()->text('x', align: 'left');
    }

    public function test_rejects_bad_values(): void
    {
        foreach ([
            fn () => Receipt::make(0),
            fn () => Receipt::make()->feed(0),
            fn () => Receipt::make()->qr(''),
        ] as $i => $make) {
            try {
                $make();
                $this->fail("case {$i} did not throw");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
