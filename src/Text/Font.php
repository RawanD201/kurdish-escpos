<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Text;

use Rawand\KurdishEscpos\Exceptions\KurdishEscposException;

/**
 * Reads TrueType and OpenType font files: metrics, the character map, glyph
 * outlines, and the GDEF, GSUB and GPOS layout tables.
 *
 * Variable fonts are read at their default instance; outline and metric
 * variations are not applied.
 */
final class Font
{
    public readonly int $unitsPerEm;

    public readonly int $ascender;

    /** Descender in font units; negative values lie below the baseline. */
    public readonly int $descender;

    public readonly int $lineGap;

    public readonly int $glyphCount;

    /** @var array<string, int> table tag => offset */
    private array $tables = [];

    /** @var array<int, int>|null code point => glyph ID */
    private ?array $cmap = null;

    private int $numberOfHMetrics;

    private int $locaFormat;

    /** @var array<int, int> */
    private array $glyphClasses = [];

    /** @var array<int, int> */
    private array $markAttachClasses = [];

    /** @var list<array<int, int>> */
    private array $markSets = [];

    private ?LayoutTable $gsub = null;

    private ?LayoutTable $gpos = null;

    private bool $layoutLoaded = false;

    /** @var array<int, list<list<array{float, float, bool}>>> */
    private array $outlines = [];

    /** @var array<int, int>|null (left glyph << 16 | right glyph) => kerning in font units */
    private ?array $kernPairs = null;

    /** @var array<string, self> */
    private static array $cache = [];

    public function __construct(public readonly string $data)
    {
        $signature = substr($data, 0, 4);
        if ($signature === 'ttcf') {
            throw new KurdishEscposException('Font collections (.ttc) are not supported; use a single .ttf font.');
        }
        if (strlen($data) < 12 || ! in_array($signature, ["\x00\x01\x00\x00", 'true', 'OTTO'], true)) {
            throw new KurdishEscposException('Not a TrueType or OpenType font file.');
        }
        $count = Bytes::u16($data, 4);
        if (12 + 16 * $count > strlen($data)) {
            throw new KurdishEscposException('The font file is damaged (table directory is cut off).');
        }
        for ($i = 0; $i < $count; $i++) {
            $record = 12 + 16 * $i;
            $offset = Bytes::u32($data, $record + 8);
            if ($offset + Bytes::u32($data, $record + 12) > strlen($data)) {
                throw new KurdishEscposException('The font file is damaged (a table is cut off).');
            }
            $this->tables[substr($data, $record, 4)] = $offset;
        }
        foreach (['head', 'hhea', 'hmtx', 'maxp', 'cmap', 'loca', 'glyf'] as $tag) {
            if (! isset($this->tables[$tag])) {
                throw new KurdishEscposException(isset($this->tables['CFF ']) || isset($this->tables['CFF2'])
                    ? 'This font uses PostScript (CFF) outlines, usually an .otf file. Only TrueType (.ttf) outlines are supported.'
                    : "Font has no {$tag} table.");
            }
        }

        $head = $this->tables['head'];
        $this->unitsPerEm = Bytes::u16($data, $head + 18);
        $this->locaFormat = Bytes::i16($data, $head + 50);

        $hhea = $this->tables['hhea'];
        $this->numberOfHMetrics = Bytes::u16($data, $hhea + 34);
        $this->glyphCount = Bytes::u16($data, $this->tables['maxp'] + 4);

        // As in HarfBuzz: OS/2 typographic metrics when USE_TYPO_METRICS is set, hhea otherwise.
        $os2 = $this->tables['OS/2'] ?? null;
        if ($os2 !== null && (Bytes::u16($data, $os2 + 62) & 0x80)) {
            [$this->ascender, $this->descender, $this->lineGap] = [Bytes::i16($data, $os2 + 68), Bytes::i16($data, $os2 + 70), Bytes::i16($data, $os2 + 72)];
        } else {
            [$this->ascender, $this->descender, $this->lineGap] = [Bytes::i16($data, $hhea + 4), Bytes::i16($data, $hhea + 6), Bytes::i16($data, $hhea + 8)];
        }
    }

    /** Loads a font file, caching the parsed font for the lifetime of the process. */
    public static function load(string $path): self
    {
        if (! isset(self::$cache[$path])) {
            $data = @file_get_contents($path);
            if ($data === false) {
                throw new KurdishEscposException("Could not read the font {$path}.");
            }
            self::$cache[$path] = new self($data);
        }

        return self::$cache[$path];
    }

    /** Returns the bundled Vazirmatn font, which covers Kurdish, Arabic and Latin. */
    public static function default(): self
    {
        return self::load(dirname(__DIR__, 2).'/resources/fonts/Vazirmatn.ttf');
    }

    /** Returns the full font name from the name table, for example "Vazirmatn Regular". */
    public function name(): string
    {
        $name = $this->tables['name'] ?? null;
        if ($name === null) {
            return '';
        }
        $d = $this->data;
        $count = Bytes::u16($d, $name + 2);
        $strings = $name + Bytes::u16($d, $name + 4);
        $found = [];
        for ($i = 0; $i < $count; $i++) {
            $r = $name + 6 + 12 * $i;
            $platform = Bytes::u16($d, $r);
            $nameId = Bytes::u16($d, $r + 6);
            if (($nameId === 4 || $nameId === 1) && ($platform === 3 || $platform === 0)) {
                $raw = substr($d, $strings + Bytes::u16($d, $r + 10), Bytes::u16($d, $r + 8));
                $found[$nameId] ??= self::utf16ToUtf8($raw);
            }
        }

        return $found[4] ?? $found[1] ?? '';
    }

    /**
     * Returns the variation axes of a variable font, for example
     * ["wght" => [100.0, 400.0, 900.0]]. Only default values are used.
     *
     * @return array<string, array{float, float, float}> tag => [min, default, max]
     */
    public function variationAxes(): array
    {
        $fvar = $this->tables['fvar'] ?? null;
        if ($fvar === null) {
            return [];
        }
        $d = $this->data;
        $axes = [];
        $first = $fvar + Bytes::u16($d, $fvar + 4);
        $size = Bytes::u16($d, $fvar + 10);
        for ($i = 0, $n = Bytes::u16($d, $fvar + 8); $i < $n; $i++) {
            $a = $first + $size * $i;
            $fixed = fn (int $o) => (Bytes::u32($d, $o) >= 0x80000000 ? Bytes::u32($d, $o) - 0x100000000 : Bytes::u32($d, $o)) / 65536;
            $axes[substr($d, $a, 4)] = [$fixed($a + 4), $fixed($a + 8), $fixed($a + 12)];
        }

        return $axes;
    }

    /** Returns the glyph ID for a code point, or 0 (.notdef) when the font has none. */
    public function glyphId(int $codepoint): int
    {
        $this->cmap ??= $this->readCmap();

        return $this->cmap[$codepoint] ?? 0;
    }

    public function hasGlyph(int $codepoint): bool
    {
        return $this->glyphId($codepoint) !== 0;
    }

    /** Returns the horizontal advance of a glyph in font units. */
    public function advance(int $glyph): int
    {
        $index = min($glyph, $this->numberOfHMetrics - 1);

        return Bytes::u16($this->data, $this->tables['hmtx'] + 4 * $index);
    }

    /** Returns the GDEF glyph class: 1 base, 2 ligature, 3 mark, 4 component, 0 unclassified. */
    public function glyphClass(int $glyph): int
    {
        $this->loadLayout();

        return $this->glyphClasses[$glyph] ?? 0;
    }

    public function markAttachClass(int $glyph): int
    {
        $this->loadLayout();

        return $this->markAttachClasses[$glyph] ?? 0;
    }

    public function inMarkSet(int $set, int $glyph): bool
    {
        $this->loadLayout();

        return isset($this->markSets[$set][$glyph]);
    }

    /**
     * Returns the kerning value from the legacy TrueType kern table (format 0).
     * It applies only to fonts without GPOS kerning.
     */
    public function legacyKerning(int $left, int $right): int
    {
        return ($this->kernPairs ??= $this->readKernTable())[($left << 16) | $right] ?? 0;
    }

    public function hasLegacyKerning(): bool
    {
        return ($this->kernPairs ??= $this->readKernTable()) !== [];
    }

    /** @return array<int, int> */
    private function readKernTable(): array
    {
        $kern = $this->tables['kern'] ?? null;
        if ($kern === null || Bytes::u16($this->data, $kern) !== 0) {
            return []; // absent, or Apple's version 1 format
        }
        $d = $this->data;
        $pairs = [];
        $p = $kern + 4;
        for ($t = 0, $n = Bytes::u16($d, $kern + 2); $t < $n; $t++) {
            $length = Bytes::u16($d, $p + 2);
            $coverage = Bytes::u16($d, $p + 4);
            // Format 0, horizontal, not cross-stream.
            if (($coverage >> 8) === 0 && ($coverage & 0x01) && ! ($coverage & 0x04)) {
                for ($i = 0, $count = Bytes::u16($d, $p + 6); $i < $count; $i++) {
                    $r = $p + 14 + 6 * $i;
                    $key = (Bytes::u16($d, $r) << 16) | Bytes::u16($d, $r + 2);
                    $pairs[$key] = ($pairs[$key] ?? 0) + Bytes::i16($d, $r + 4);
                }
            }
            $p += $length;
        }

        return $pairs;
    }

    public function gsub(): ?LayoutTable
    {
        $this->loadLayout();

        return $this->gsub;
    }

    public function gpos(): ?LayoutTable
    {
        $this->loadLayout();

        return $this->gpos;
    }

    /**
     * Returns the glyph outline in font units, with y increasing upward: a
     * list of closed contours, each a list of [x, y, onCurve] quadratic points.
     *
     * @return list<list<array{float, float, bool}>>
     */
    public function outline(int $glyph): array
    {
        return $this->outlines[$glyph] ??= $this->readOutline($glyph, 0);
    }

    private static function utf16ToUtf8(string $raw): string
    {
        return (string) @iconv('UTF-16BE', 'UTF-8//IGNORE', $raw);
    }

    /** @return array<int, int> */
    private function readCmap(): array
    {
        $d = $this->data;
        $cmap = $this->tables['cmap'];
        $best = null;
        $bestScore = 0;
        $count = Bytes::u16($d, $cmap + 2);
        for ($i = 0; $i < $count; $i++) {
            $record = $cmap + 4 + 8 * $i;
            $platform = Bytes::u16($d, $record);
            $encoding = Bytes::u16($d, $record + 2);
            $offset = $cmap + Bytes::u32($d, $record + 4);
            $format = Bytes::u16($d, $offset);
            // Prefer full Unicode coverage (format 12), then the BMP (format 4).
            $score = match (true) {
                $format === 12 && ($platform === 0 || ($platform === 3 && $encoding === 10)) => 2,
                $format === 4 && ($platform === 0 || ($platform === 3 && $encoding === 1)) => 1,
                default => 0,
            };
            if ($score > $bestScore) {
                [$best, $bestScore] = [$offset, $score];
            }
        }
        if ($best === null) {
            throw new KurdishEscposException('Font has no Unicode character map.');
        }

        $map = [];
        if ($bestScore === 2) {
            $groups = Bytes::u32($d, $best + 12);
            for ($i = 0; $i < $groups; $i++) {
                $g = $best + 16 + 12 * $i;
                $first = Bytes::u32($d, $g);
                $last = Bytes::u32($d, $g + 4);
                $glyph = Bytes::u32($d, $g + 8);
                for ($c = $first; $c <= $last; $c++) {
                    $map[$c] = $glyph++;
                }
            }

            return $map;
        }

        $segments = Bytes::u16($d, $best + 6) >> 1;
        $ends = $best + 14;
        $starts = $ends + 2 * $segments + 2;
        $deltas = $starts + 2 * $segments;
        $rangeOffsets = $deltas + 2 * $segments;
        for ($s = 0; $s < $segments; $s++) {
            $start = Bytes::u16($d, $starts + 2 * $s);
            $end = Bytes::u16($d, $ends + 2 * $s);
            $delta = Bytes::u16($d, $deltas + 2 * $s);
            $rangeOffset = Bytes::u16($d, $rangeOffsets + 2 * $s);
            for ($c = $start; $c <= $end && $c !== 0xFFFF; $c++) {
                if ($rangeOffset === 0) {
                    $glyph = ($c + $delta) & 0xFFFF;
                } else {
                    $glyph = Bytes::u16($d, $rangeOffsets + 2 * $s + $rangeOffset + 2 * ($c - $start));
                    if ($glyph !== 0) {
                        $glyph = ($glyph + $delta) & 0xFFFF;
                    }
                }
                if ($glyph !== 0) {
                    $map[$c] = $glyph;
                }
            }
        }

        return $map;
    }

    private function loadLayout(): void
    {
        if ($this->layoutLoaded) {
            return;
        }
        $this->layoutLoaded = true;

        if (isset($this->tables['GDEF'])) {
            $gdef = $this->tables['GDEF'];
            $minor = Bytes::u16($this->data, $gdef + 2);
            if ($offset = Bytes::u16($this->data, $gdef + 4)) {
                $this->glyphClasses = LayoutTable::readClassDef($this->data, $gdef + $offset);
            }
            if ($offset = Bytes::u16($this->data, $gdef + 10)) {
                $this->markAttachClasses = LayoutTable::readClassDef($this->data, $gdef + $offset);
            }
            if ($minor >= 2 && ($offset = Bytes::u16($this->data, $gdef + 12))) {
                $sets = $gdef + $offset;
                $count = Bytes::u16($this->data, $sets + 2);
                for ($i = 0; $i < $count; $i++) {
                    $this->markSets[] = LayoutTable::readCoverage($this->data, $sets + Bytes::u32($this->data, $sets + 4 + 4 * $i));
                }
            }
        }
        if (isset($this->tables['GSUB'])) {
            $this->gsub = new LayoutTable($this->data, $this->tables['GSUB'], false);
        }
        if (isset($this->tables['GPOS'])) {
            $this->gpos = new LayoutTable($this->data, $this->tables['GPOS'], true);
        }
    }

    /** @return list<list<array{float, float, bool}>> */
    private function readOutline(int $glyph, int $depth): array
    {
        if ($glyph >= $this->glyphCount || $depth > 8) {
            return [];
        }
        $d = $this->data;
        $loca = $this->tables['loca'];
        if ($this->locaFormat === 0) {
            $start = 2 * Bytes::u16($d, $loca + 2 * $glyph);
            $end = 2 * Bytes::u16($d, $loca + 2 * $glyph + 2);
        } else {
            $start = Bytes::u32($d, $loca + 4 * $glyph);
            $end = Bytes::u32($d, $loca + 4 * $glyph + 4);
        }
        if ($end <= $start) {
            return []; // empty glyph, for example a space
        }
        $p = $this->tables['glyf'] + $start;
        $contourCount = Bytes::i16($d, $p);

        return $contourCount >= 0
            ? $this->readSimpleGlyph($p, $contourCount)
            : $this->readCompositeGlyph($p, $depth);
    }

    /** @return list<list<array{float, float, bool}>> */
    private function readSimpleGlyph(int $p, int $contourCount): array
    {
        $d = $this->data;
        $p += 10;
        $ends = [];
        for ($i = 0; $i < $contourCount; $i++) {
            $ends[] = Bytes::u16($d, $p);
            $p += 2;
        }
        $pointCount = $contourCount > 0 ? end($ends) + 1 : 0;
        $p += 2 + Bytes::u16($d, $p); // skip hinting instructions

        $flags = [];
        while (count($flags) < $pointCount) {
            $flag = ord($d[$p++]);
            $flags[] = $flag;
            if ($flag & 0x08) {
                for ($repeat = ord($d[$p++]); $repeat > 0; $repeat--) {
                    $flags[] = $flag;
                }
            }
        }

        $xs = [];
        $x = 0;
        foreach ($flags as $i => $flag) {
            if ($flag & 0x02) {
                $dx = ord($d[$p++]);
                $x += ($flag & 0x10) ? $dx : -$dx;
            } elseif (! ($flag & 0x10)) {
                $x += Bytes::i16($d, $p);
                $p += 2;
            }
            $xs[$i] = $x;
        }
        $points = [];
        $y = 0;
        foreach ($flags as $i => $flag) {
            if ($flag & 0x04) {
                $dy = ord($d[$p++]);
                $y += ($flag & 0x20) ? $dy : -$dy;
            } elseif (! ($flag & 0x20)) {
                $y += Bytes::i16($d, $p);
                $p += 2;
            }
            $points[] = [(float) $xs[$i], (float) $y, (bool) ($flag & 0x01)];
        }

        $contours = [];
        $first = 0;
        foreach ($ends as $last) {
            $contours[] = array_slice($points, $first, $last - $first + 1);
            $first = $last + 1;
        }

        return $contours;
    }

    /** @return list<list<array{float, float, bool}>> */
    private function readCompositeGlyph(int $p, int $depth): array
    {
        $d = $this->data;
        $p += 10;
        $contours = [];
        do {
            $flags = Bytes::u16($d, $p);
            $component = Bytes::u16($d, $p + 2);
            $p += 4;
            if ($flags & 0x0001) { // ARG_1_AND_2_ARE_WORDS
                [$arg1, $arg2] = [Bytes::i16($d, $p), Bytes::i16($d, $p + 2)];
                $p += 4;
            } else {
                [$arg1, $arg2] = [Bytes::i8($d, $p), Bytes::i8($d, $p + 1)];
                $p += 2;
            }
            // Point-matching arguments (no ARGS_ARE_XY_VALUES) are rare and not supported.
            [$dx, $dy] = ($flags & 0x0002) ? [$arg1, $arg2] : [0, 0];
            [$a, $b, $c, $e] = [1.0, 0.0, 0.0, 1.0];
            if ($flags & 0x0008) { // WE_HAVE_A_SCALE
                $a = $e = Bytes::f2dot14($d, $p);
                $p += 2;
            } elseif ($flags & 0x0040) { // WE_HAVE_AN_X_AND_Y_SCALE
                [$a, $e] = [Bytes::f2dot14($d, $p), Bytes::f2dot14($d, $p + 2)];
                $p += 4;
            } elseif ($flags & 0x0080) { // WE_HAVE_A_TWO_BY_TWO
                [$a, $b, $c, $e] = [Bytes::f2dot14($d, $p), Bytes::f2dot14($d, $p + 2), Bytes::f2dot14($d, $p + 4), Bytes::f2dot14($d, $p + 6)];
                $p += 8;
            }
            foreach ($this->readOutline($component, $depth + 1) as $contour) {
                $contours[] = array_map(
                    fn (array $pt) => [$a * $pt[0] + $c * $pt[1] + $dx, $b * $pt[0] + $e * $pt[1] + $dy, $pt[2]],
                    $contour,
                );
            }
        } while ($flags & 0x0020); // MORE_COMPONENTS

        return $contours;
    }
}
