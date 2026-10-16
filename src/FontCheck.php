<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos;

use Rawand\KurdishEscpos\Exceptions\KurdishEscposException;
use Rawand\KurdishEscpos\Text\Font;
use Rawand\KurdishEscpos\Text\Unicode;

/**
 * Inspects a font file and reports whether it is suitable for printing
 * Kurdish text with this package.
 *
 *     $result = FontCheck::check('/fonts/NRT-Reg.ttf');
 *     $result['ok'];        // false when Kurdish text cannot be printed correctly
 *     $result['problems'];  // reasons the font is unsuitable
 *     $result['notes'];     // limitations that do not prevent printing
 */
final class FontCheck
{
    /** The Sorani alphabet, including the letters that Arabic-only fonts lack. */
    public const KURDISH_LETTERS = 'ئابپتجچحخدرڕزژسشعغفڤقکگلڵمنهەوۆیێ';

    /** GSUB and GPOS lookup types supported by the shaper. */
    private const SUPPORTED = ['GSUB' => [1, 2, 3, 4, 5, 6], 'GPOS' => [1, 2, 3, 4, 5, 6, 7, 8]];

    private const LOOKUP_NAMES = [
        'GSUB' => [8 => 'reverse chaining substitution'],
        'GPOS' => [],
    ];

    /**
     * @return array{ok: bool, name: string, problems: list<string>, notes: list<string>}
     */
    public static function check(string $path): array
    {
        try {
            $font = Font::load($path);
        } catch (KurdishEscposException $e) {
            return ['ok' => false, 'name' => basename($path), 'problems' => [$e->getMessage()], 'notes' => []];
        }
        $problems = [];
        $notes = [];

        $missing = self::missing($font, self::KURDISH_LETTERS);
        if ($missing !== '') {
            $problems[] = "Missing Kurdish letters: {$missing}. They will be drawn with the fallback font (Vazirmatn), "
                .'and words that contain them will not join correctly.';
        }

        $gsub = $font->gsub();
        $joining = $gsub?->featureLookups('arab', 'KUR ', ['init', 'medi', 'fina']) ?? [];
        if ($joining === []) {
            $problems[] = 'The font has no OpenType joining rules (init, medi, fina) for Arabic script, '
                .'so letters will print unjoined. Old non-Unicode Kurdish fonts (such as the Ali-K family) work like this.';
        }

        foreach (['GSUB' => $gsub, 'GPOS' => $font->gpos()] as $tag => $table) {
            if ($table === null) {
                continue;
            }
            $features = $tag === 'GSUB'
                ? ['ccmp', 'locl', 'isol', 'fina', 'medi', 'init', 'rlig', 'calt', 'liga', 'clig']
                : ['kern', 'mark', 'mkmk', 'curs'];
            foreach (array_diff($table->lookupTypes('arab', 'KUR ', $features), self::SUPPORTED[$tag]) as $type) {
                $what = self::LOOKUP_NAMES[$tag][$type] ?? "lookup type {$type}";
                $notes[] = "Uses {$tag} {$what}, which is not supported yet; a few letter shapes may differ from other apps.";
            }
        }

        if (($latin = self::missing($font, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789')) !== '') {
            $notes[] = 'Has no English letters or digits ('.substr($latin, 0, 12).'...); those are drawn with Vazirmatn.';
        }
        if (($digits = self::missing($font, '٠١٢٣٤٥٦٧٨٩')) !== '') {
            $notes[] = "Has no Arabic-Indic digits ({$digits}); those are drawn with Vazirmatn.";
        }
        foreach ($font->variationAxes() as $axis => [$min, $default, $max]) {
            $notes[] = "Variable font: the default {$axis} value ({$default}) is used, not the range {$min}-{$max}.";
        }

        return ['ok' => $problems === [], 'name' => $font->name() ?: basename($path), 'problems' => $problems, 'notes' => $notes];
    }

    /** Returns a sample receipt that exercises Kurdish joining, digits and Latin text. */
    public static function sampleReceipt(int $width = Receipt::WIDTH_80MM): Receipt
    {
        return Receipt::make($width)
            ->title('دەرمانخانەی ئازادی')
            ->text('سلێمانی، شەقامی سالم', align: 'center')
            ->divider()
            ->row('کوڵێرە', '1,250')
            ->row('هەڵەبجە و باڵندە', '٥٠٠')
            ->row('ڤیتامین C (500mg)', '12,500')
            ->divider()
            ->row('کۆی گشتی', '14,250 دینار', size: 30)
            ->text('ڕۆژێکی خۆش، سوپاس بۆ کڕینەکەت', align: 'center')
            ->text('Thank you, spas!', align: 'center');
    }

    private static function missing(Font $font, string $characters): string
    {
        return implode('', array_map(
            fn (int $cp) => (string) iconv('UTF-32BE', 'UTF-8', pack('N', $cp)),
            array_values(array_filter(Unicode::codepoints($characters), fn (int $cp) => ! $font->hasGlyph($cp))),
        ));
    }
}
