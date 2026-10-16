<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rawand\KurdishEscpos\Text\Bidi;
use Rawand\KurdishEscpos\Text\Font;
use Rawand\KurdishEscpos\Text\Line;
use Rawand\KurdishEscpos\Text\TextLayout;
use Rawand\KurdishEscpos\Text\Unicode;

final class TextLayoutTest extends TestCase
{
    private static function layout(string $text, float $maxWidth = 10000): array
    {
        return (new TextLayout([Font::default()]))->layout($text, 28, $maxWidth);
    }

    /** @return iterable<string, array{array{text: string, rtl: bool, visual: string}}> */
    public static function bidiCases(): iterable
    {
        foreach (json_decode((string) file_get_contents(__DIR__.'/fixtures/bidi.json'), true) as $case) {
            yield ($case['rtl'] ? 'rtl: ' : 'ltr: ').$case['text'] => [$case];
        }
    }

    /**
     * Visual order must match the reference in tests/fixtures/bidi.json,
     * recorded with python-bidi. The reference does not mirror brackets, so
     * neither does this comparison.
     *
     * @param  array{text: string, rtl: bool, visual: string}  $case
     */
    #[DataProvider('bidiCases')]
    public function test_bidi_order_matches_python_bidi(array $case): void
    {
        $cps = Unicode::codepoints($case['text']);
        $level = $case['rtl'] ? 1 : 0;
        $bidi = Bidi::analyze($cps, $level);
        $levels = Bidi::lineLevels($bidi['levels'], $bidi['classes'], $level);

        $visual = implode('', array_map(fn (int $i) => mb_chr($cps[$i]), Bidi::visualOrder($levels)));

        $this->assertSame($case['visual'], $visual);
    }

    public function test_paragraph_direction(): void
    {
        foreach ([
            'سلێمانی' => true,
            'Azadi Pharmacy' => false,
            '1042 ژمارەی وەسڵ' => true,      // digits are not strong
            'Total: کۆی گشتی' => false,      // the first letter decides
            'Hêvî û şahî' => false,          // Latin-script Kurmanji
            '2026-10-16 14:30' => true,      // no letters: right-to-left
            '' => true,
        ] as $text => $rtl) {
            $this->assertSame($rtl, TextLayout::isRtl(Unicode::codepoints((string) $text)), (string) $text);
        }
    }

    /**
     * In a right-to-left line, words that come first in the text are drawn
     * further right, while the digits of a number still read left to right.
     */
    public function test_mixed_line_order_by_x_position(): void
    {
        $text = 'کۆی گشتی 16,750 دینار';
        [$line] = self::layout($text);
        $x = fn (string $part) => self::xOfCharacter($line, mb_strpos($text, $part));

        $this->assertGreaterThan($x('16'), $x('کۆی'), 'کۆی is right of the number');
        $this->assertGreaterThan($x('دینار'), $x('16'), 'the number is right of دینار');
        $this->assertLessThan($x('750'), $x('16'), '16 comes before 750, left to right');
    }

    public function test_wraps_long_text_at_spaces(): void
    {
        $text = 'ڤیتامین C هەڵپڕژێنراو، دەبەی گەورە بۆ هەموو ئەندامانی خێزان';
        $lines = self::layout($text, 300);

        $this->assertGreaterThan(1, count($lines));
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(300, $line->width);
            $this->assertNotEmpty($line->glyphs);
        }
        // Every word is still there: the glyph count matches the unwrapped line minus the dropped spaces.
        $all = count(self::layout($text)[0]->glyphs);
        $this->assertSame($all - (count($lines) - 1), array_sum(array_map(fn (Line $l) => count($l->glyphs), $lines)));
    }

    public function test_newlines_start_paragraphs_with_their_own_direction(): void
    {
        $lines = self::layout("سوپاس\nThank you\n");

        $this->assertCount(3, $lines);
        $this->assertTrue($lines[0]->rtl);
        $this->assertFalse($lines[1]->rtl);
        $this->assertSame([], $lines[2]->glyphs);
        $this->assertGreaterThan(0, $lines[2]->height());
    }

    private static function xOfCharacter(Line $line, int $index): float
    {
        foreach ($line->glyphs as [, $x, , $cluster]) {
            if ($cluster === $index) {
                return $x;
            }
        }
        self::fail("no glyph for character {$index}");
    }
}
