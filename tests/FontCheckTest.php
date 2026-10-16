<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Tests;

use PHPUnit\Framework\TestCase;
use Rawand\KurdishEscpos\EscPos\Bitmap;
use Rawand\KurdishEscpos\FontCheck;
use Rawand\KurdishEscpos\Renderer;
use Rawand\KurdishEscpos\Text\Font;
use Rawand\KurdishEscpos\Text\TextLayout;

final class FontCheckTest extends TestCase
{
    private const NASKH = __DIR__.'/fixtures/NotoNaskhArabic-Subset.ttf';

    public function test_bundled_font_is_ok(): void
    {
        $result = FontCheck::check(dirname(__DIR__).'/resources/fonts/Vazirmatn.ttf');

        $this->assertTrue($result['ok']);
        $this->assertSame('Vazirmatn Regular', $result['name']);
        $this->assertSame([], $result['problems']);
    }

    public function test_font_without_english_is_ok_with_a_note(): void
    {
        $result = FontCheck::check(self::NASKH);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Has no English letters', implode(' ', $result['notes']));
    }

    public function test_reports_files_that_are_not_fonts(): void
    {
        $result = FontCheck::check(__FILE__);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['problems']);
    }

    /**
     * A custom font prints Kurdish with its own letter shapes, and English
     * (which this font does not have) with the bundled fallback font.
     */
    public function test_custom_font_with_fallback_for_missing_letters(): void
    {
        $naskh = Font::load(self::NASKH);
        [$line] = (new TextLayout([$naskh, Font::default()]))->layout('کوڵێرە Thank you 5', 28, 1000);

        $fontOf = [];
        foreach ($line->glyphs as [, , , $cluster, $font]) {
            $fontOf[$cluster] = $font->name();
        }
        $this->assertSame('Noto Naskh Arabic Regular', $fontOf[0], 'Kurdish uses the custom font');
        $this->assertSame('Vazirmatn Regular', $fontOf[7], 'English falls back to Vazirmatn');

        $custom = (new Renderer(self::NASKH))->render(FontCheck::sampleReceipt())->escpos;
        $this->assertNotSame((new Renderer)->render(FontCheck::sampleReceipt())->escpos, $custom);
        $rows = Bitmap::fromEscPos($custom)->rows;
        $this->assertNotSame(str_repeat("\0", strlen($rows)), $rows, 'something is printed');
    }
}
