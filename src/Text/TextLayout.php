<?php

declare(strict_types=1);

namespace RawanD201\KurdishEscpos\Text;

/**
 * Lays out paragraphs: determines the paragraph direction, breaks lines at
 * spaces, orders mixed-direction text and shapes each run.
 *
 * Each character uses the first font that contains it, so characters
 * missing from the main font are drawn with the fallback font.
 */
final class TextLayout
{
    /** @var list<Font> */
    private readonly array $fonts;

    /** @var list<Shaper> */
    private readonly array $shapers;

    /** @param  array<Font>  $fonts  The main font first, followed by fallback fonts. */
    public function __construct(array $fonts)
    {
        if ($fonts === []) {
            throw new \InvalidArgumentException('At least one font is needed.');
        }
        $this->fonts = array_values($fonts);
        $this->shapers = array_map(fn (Font $font) => new Shaper($font), $this->fonts);
    }

    /**
     * Lays out text that may contain several paragraphs separated by "\n".
     *
     * @return list<Line>
     */
    public function layout(string $text, float $size, float $maxWidth): array
    {
        $lines = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $paragraph) {
            array_push($lines, ...$this->layoutParagraph(Unicode::codepoints($paragraph), $size, $maxWidth));
        }

        return $lines;
    }

    /**
     * Rule P2: the first strong character determines the direction. Text
     * without strong characters, such as a number, is treated as
     * right-to-left, since the package primarily prints Kurdish.
     *
     * @param  list<int>  $cps
     */
    public static function isRtl(array $cps): bool
    {
        foreach ($cps as $cp) {
            $class = Unicode::bidiClass($cp);
            if ($class === 'L') {
                return false;
            }
            if ($class === 'R' || $class === 'AL') {
                return true;
            }
        }

        return true;
    }

    /**
     * @param  list<int>  $cps
     * @return list<Line>
     */
    private function layoutParagraph(array $cps, float $size, float $maxWidth): array
    {
        $rtl = self::isRtl($cps);
        if ($cps === []) {
            return [$this->makeLine([], 0.0, $rtl, $size)];
        }

        $level = $rtl ? 1 : 0;
        $bidi = Bidi::analyze($cps, $level);
        $scripts = self::resolveScripts($cps);
        $fonts = $this->resolveFonts($cps);

        // Shape the whole paragraph once to measure each character in dots.
        $advances = array_fill(0, count($cps), 0.0);
        foreach ($this->runs($bidi['levels'], $scripts, $fonts, 0, count($cps)) as [$start, $end, $runLevel, $script, $font]) {
            $scale = $size / $this->fonts[$font]->unitsPerEm;
            foreach ($this->shapers[$font]->shape($cps, $start, $end, ($runLevel & 1) === 1, $script) as $glyph) {
                $advances[$glyph->cluster] += $glyph->xAdvance * $scale;
            }
        }

        $lines = [];
        foreach ($this->breakLines($cps, $advances, $maxWidth) as [$start, $end]) {
            $levels = Bidi::lineLevels(
                array_slice($bidi['levels'], $start, $end - $start),
                array_slice($bidi['classes'], $start, $end - $start),
                $level,
            );
            $lines[] = $this->shapeLine($cps, $start, $levels, $scripts, $fonts, $rtl, $size);
        }

        return $lines;
    }

    /**
     * Greedy line breaking at spaces. A word wider than the line is broken
     * between characters.
     *
     * @param  list<int>  $cps
     * @param  list<float>  $advances  width of each character in dots
     * @return list<array{int, int}> [start, end) of each line, without the spaces at the break
     */
    private function breakLines(array $cps, array $advances, float $maxWidth): array
    {
        $n = count($cps);
        $isSpace = fn (int $i) => $i < $n && Unicode::bidiClass($cps[$i]) === 'WS';
        $lines = [];
        $start = 0;
        while ($start < $n) {
            $width = 0.0;
            $lastBreak = null; // end of the last word that fits
            $end = $start;
            while ($end < $n) {
                $width += $advances[$end];
                if ($width > $maxWidth && ! $isSpace($end)) {
                    break;
                }
                $end++;
                if ($isSpace($end) && ! $isSpace($end - 1)) {
                    $lastBreak = $end;
                }
            }
            if ($end >= $n) {
                $lineEnd = $n;
            } elseif ($lastBreak !== null) {
                $lineEnd = $lastBreak;
            } else {
                // A single word is wider than the line: break it, keeping marks with their base.
                $lineEnd = max($end, $start + 1);
                while ($lineEnd < $n && Unicode::isMark($cps[$lineEnd])) {
                    $lineEnd++;
                }
            }
            $trimmed = $lineEnd;
            while ($trimmed > $start && $isSpace($trimmed - 1)) {
                $trimmed--;
            }
            $lines[] = [$start, $trimmed];
            $start = $lineEnd;
            while ($isSpace($start)) {
                $start++;
            }
        }

        return $lines;
    }

    /**
     * @param  list<int>  $cps
     * @param  list<int>  $levels  levels of this line's characters
     * @param  list<string>  $scripts
     * @param  list<int>  $fonts
     */
    private function shapeLine(array $cps, int $start, array $levels, array $scripts, array $fonts, bool $rtl, float $size): Line
    {
        $count = count($levels);
        $runs = $this->runs($levels, array_slice($scripts, $start, $count), array_slice($fonts, $start, $count), 0, $count);
        $order = Bidi::visualOrder(array_map(fn ($run) => $run[2], $runs));

        $glyphs = [];
        $x = 0.0;
        foreach ($order as $r) {
            [$runStart, $runEnd, $runLevel, $script, $fontIndex] = $runs[$r];
            $font = $this->fonts[$fontIndex];
            $scale = $size / $font->unitsPerEm;
            foreach ($this->shapers[$fontIndex]->shape($cps, $start + $runStart, $start + $runEnd, ($runLevel & 1) === 1, $script) as $g) {
                $glyphs[] = [$g->id, $x + $g->xOffset * $scale, $g->yOffset * $scale, $g->cluster, $font];
                $x += $g->xAdvance * $scale;
            }
        }

        return $this->makeLine($glyphs, $x, $rtl, $size);
    }

    /** @param  list<array{int, float, float, int, Font}>  $glyphs */
    private function makeLine(array $glyphs, float $width, bool $rtl, float $size): Line
    {
        $main = $this->fonts[0];
        $scale = $size / $main->unitsPerEm;

        return new Line($glyphs, $width, $rtl, $size, $main->ascender * $scale, -$main->descender * $scale);
    }

    /**
     * Splits [$start, $end) into runs of one level, one script and one font.
     *
     * @param  list<int>  $levels
     * @param  list<string>  $scripts
     * @param  list<int>  $fonts
     * @return list<array{int, int, int, string, int}> [start, end, level, script, font index]
     */
    private function runs(array $levels, array $scripts, array $fonts, int $start, int $end): array
    {
        $runs = [];
        for ($i = $start; $i < $end; $i = $j) {
            $j = $i + 1;
            while ($j < $end && $levels[$j] === $levels[$i] && $scripts[$j] === $scripts[$i] && $fonts[$j] === $fonts[$i]) {
                $j++;
            }
            $runs[] = [$i, $j, $levels[$i], $scripts[$i], $fonts[$i]];
        }

        return $runs;
    }

    /**
     * Selects a font for each character: the first font that contains it.
     * Marks, spaces, digits and punctuation keep the previous character's
     * font when that font contains them, so words are not split across fonts.
     *
     * @param  list<int>  $cps
     * @return list<int> font index per character
     */
    private function resolveFonts(array $cps): array
    {
        $result = [];
        $previous = 0;
        foreach ($cps as $i => $cp) {
            $script = Unicode::script($cp);
            $follows = $i > 0 && ($script === 'common' || $script === 'inherited' || Unicode::isDefaultIgnorable($cp));
            if ($follows && ($this->fonts[$previous]->hasGlyph($cp) || Unicode::isDefaultIgnorable($cp))) {
                $result[] = $previous;

                continue;
            }
            $choice = 0;
            foreach ($this->fonts as $k => $font) {
                if ($font->hasGlyph($cp)) {
                    $choice = $k;
                    break;
                }
            }
            $result[] = $previous = $choice;
        }

        return $result;
    }

    /**
     * Assigns digits, punctuation and marks the script of the surrounding
     * text, so that they are shaped in the same run.
     *
     * @param  list<int>  $cps
     * @return list<string>
     */
    private static function resolveScripts(array $cps): array
    {
        $scripts = array_map(Unicode::script(...), $cps);
        $previous = null;
        foreach ($scripts as $i => $script) {
            if ($script === 'common' || $script === 'inherited') {
                $scripts[$i] = $previous;
            } else {
                $previous = $script;
            }
        }
        // Leading digits and punctuation take the script of the first letter that follows.
        $next = 'latn';
        for ($i = count($scripts) - 1; $i >= 0; $i--) {
            if ($scripts[$i] === null) {
                $scripts[$i] = $next;
            } else {
                $next = $scripts[$i];
            }
        }

        return $scripts;
    }
}
