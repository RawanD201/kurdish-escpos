<?php

declare(strict_types=1);

namespace RawanD201\KurdishEscpos\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RawanD201\KurdishEscpos\Text\Font;
use RawanD201\KurdishEscpos\Text\Glyph;
use RawanD201\KurdishEscpos\Text\Shaper;
use RawanD201\KurdishEscpos\Text\Unicode;

final class ShaperTest extends TestCase
{
    private static function shape(string $text, bool $rtl = true, string $script = 'arab', ?Font $font = null): array
    {
        $cps = Unicode::codepoints($text);

        return (new Shaper($font ?? Font::default()))->shape($cps, 0, count($cps), $rtl, $script);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function harfBuzzCases(): iterable
    {
        foreach (glob(__DIR__.'/fixtures/harfbuzz*.json') ?: [] as $file) {
            $fixture = json_decode((string) file_get_contents($file), true);
            foreach ($fixture['cases'] as $case) {
                yield basename($fixture['font']).': '.$case['text'] => [$fixture['font'], $case];
            }
        }
    }

    /**
     * The PHP shaper must give exactly what HarfBuzz gives: the same glyphs,
     * clusters, advances and mark offsets, for every font with a fixture
     * (recorded with HarfBuzz 14.6.0).
     *
     * @param  array{text: string, rtl: bool, script: string, glyphs: list<list<int>>}  $case
     */
    #[DataProvider('harfBuzzCases')]
    public function test_matches_harfbuzz(string $font, array $case): void
    {
        $glyphs = array_map(
            fn (Glyph $g) => [$g->id, $g->cluster, $g->xAdvance, $g->xOffset, $g->yOffset],
            self::shape($case['text'], $case['rtl'], $case['script'], Font::load(dirname(__DIR__).'/'.$font)),
        );

        $this->assertSame($case['glyphs'], $glyphs);
    }

    /**
     * ڵ and ێ have no Unicode presentation forms, which is why other PHP
     * libraries print them unjoined. Inside a word they must not use the
     * stand-alone glyph.
     */
    public function test_kurdish_letters_join_inside_words(): void
    {
        foreach (['ڵ' => ['کوڵێرە', 'هەڵەبجە', 'باڵندە'], 'ێ' => ['کوڵێرە', 'سلێمانی', 'ئێوارە', 'پێنج']] as $letter => $words) {
            $isolated = self::shape($letter)[0]->id;
            foreach ($words as $word) {
                $ids = array_map(fn (Glyph $g) => $g->id, self::shape($word));
                $this->assertNotContains($isolated, $ids, "{$letter} is drawn unjoined in {$word}");
            }
        }
    }

    public function test_right_to_left_glyphs_come_out_in_visual_order(): void
    {
        $clusters = array_map(fn (Glyph $g) => $g->cluster, self::shape('سڵاو'));

        // Visual order is left to right, so the first letter (index 0) is last.
        $this->assertSame(0, end($clusters));
        $this->assertSame($clusters, array_values(array_reverse(array_unique(array_reverse($clusters)))));
    }
}
