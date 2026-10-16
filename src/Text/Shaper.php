<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Text;

/**
 * Converts a run of characters into positioned glyphs by applying the
 * font's OpenType layout rules. The implementation follows HarfBuzz for
 * Arabic script and for simple scripts such as Latin.
 *
 * Contextual forms are taken from the font, so letters without Unicode
 * presentation forms, such as ڵ and ێ, are joined correctly.
 */
final class Shaper
{
    private const GLOBAL = 1;

    /** Arabic positional features, selected per glyph by mask. */
    private const ARABIC_MASKS = ['isol' => 2, 'fina' => 4, 'fin2' => 8, 'fin3' => 16, 'medi' => 32, 'med2' => 64, 'init' => 128];

    /** GSUB features in HarfBuzz stage order. Within a stage, lookups run in lookup index order. */
    private const ARABIC_GSUB = [
        ['rtla'], ['ccmp', 'locl'], ['isol'], ['fina'], ['fin2'], ['fin3'], ['medi'], ['med2'], ['init'], ['rlig'], ['calt'],
        ['rclt', 'mset', 'abvm', 'blwm', 'mark', 'mkmk', 'clig', 'curs', 'dist', 'kern', 'liga'],
    ];

    private const DEFAULT_GSUB = [['ltra', 'rtla', 'abvm', 'blwm', 'ccmp', 'locl', 'mark', 'mkmk', 'rlig', 'calt', 'clig', 'curs', 'dist', 'kern', 'liga', 'rclt']];

    private const GPOS_FEATURES = ['abvm', 'blwm', 'curs', 'dist', 'kern', 'mark', 'mkmk'];

    /** OpenType language system tag for Kurdish. Fonts without it use their default language system. */
    private const LANGUAGE = 'KUR ';

    private const SCRIPT_TAGS = ['arab' => 'arab', 'latn' => 'latn', 'cyrl' => 'cyrl', 'grek' => 'grek'];

    /** Arabic joining state machine from HarfBuzz: [state][type] => [previous action, current action, next state]. */
    private const JOINING_STATES = [
        //  U                L                 R                  D
        [[null, null, 0], [null, 'isol', 2], [null, 'isol', 1], [null, 'isol', 2]],
        [[null, null, 0], [null, 'isol', 2], [null, 'isol', 1], [null, 'isol', 2]],
        [[null, null, 0], [null, 'isol', 2], ['init', 'fina', 1], ['init', 'fina', 3]],
        [[null, null, 0], [null, 'isol', 2], ['medi', 'fina', 1], ['medi', 'fina', 3]],
    ];

    private const JOINING_COLUMNS = ['U' => 0, 'L' => 1, 'R' => 2, 'D' => 3];

    /** Arabic modifier combining marks, which HarfBuzz moves ahead of other marks of the same class. */
    private const MODIFIER_MARKS = [0x0654, 0x0655, 0x0658, 0x06DC, 0x06E3, 0x06E7, 0x06E8, 0x08CA, 0x08CB, 0x08CD, 0x08CE, 0x08CF, 0x08D3, 0x08F3];

    /** Mark sequences longer than this are not reordered, as in HarfBuzz. */
    private const MAX_MARKS = 32;

    /** @var list<Glyph> the buffer being shaped, in logical order */
    private array $buf = [];

    private int $nextLigId = 1;

    private bool $rtl = false;

    /** @var array<string, array{gsub: list<array<int, int>>, gpos: array<int, int>}> */
    private array $plans = [];

    public function __construct(private readonly Font $font) {}

    /**
     * Shapes $codepoints[$start..$end) as a single run.
     *
     * @param  list<int>  $codepoints
     * @param  string  $script  a script from Unicode::script(), such as "arab" or "latn"
     * @return list<Glyph> in visual order (left to right); clusters index into $codepoints
     */
    public function shape(array $codepoints, int $start, int $end, bool $rtl, string $script): array
    {
        $this->buf = [];
        $this->rtl = $rtl;
        $arabic = $script === 'arab';
        for ($i = $start; $i < $end; $i++) {
            $cp = $codepoints[$i];
            if ($rtl && ($mirror = Unicode::mirror($cp)) !== null && $this->font->hasGlyph($mirror)) {
                $cp = $mirror;
            }
            $continues = $this->buf !== [] && (Unicode::isMark($cp) || $cp === 0x200D);
            $this->buf[] = new Glyph(0, $continues ? end($this->buf)->cluster : $i, $cp, self::GLOBAL);
        }
        $this->normalize($arabic);

        foreach ($this->buf as $g) {
            $g->id = $this->font->glyphId($g->codepoint);
            $g->props = $this->glyphProps($g->id);
        }
        if ($arabic) {
            $this->setJoiningMasks();
        }

        $plan = $this->plan(self::SCRIPT_TAGS[$script] ?? 'DFLT', $arabic);
        if ($gsub = $this->font->gsub()) {
            foreach ($plan['gsub'] as $stage) {
                foreach ($stage as $lookup => $mask) {
                    $this->applyLookup($gsub, $lookup, $mask);
                }
            }
        }

        foreach ($this->buf as $g) {
            $g->xAdvance = $this->font->advance($g->id);
        }
        $gpos = $this->font->gpos();
        if ($gpos) {
            foreach ($plan['gpos'] as $lookup => $mask) {
                $this->applyLookup($gpos, $lookup, $mask);
            }
        }
        if (! $gpos?->hasFeature('kern') && $this->font->hasLegacyKerning()) {
            $this->applyLegacyKerning();
        }
        $this->finishPositions($rtl);

        $glyphs = $rtl ? array_reverse($this->buf) : $this->buf;
        $this->buf = [];

        return $glyphs;
    }

    /**
     * Applies Unicode normalization as HarfBuzz does before shaping. A
     * character missing from the font is decomposed when the font has its
     * parts, marks are reordered, and base and mark pairs are recomposed when
     * the font has the composed character.
     */
    private function normalize(bool $arabic): void
    {
        $out = [];
        foreach ($this->buf as $g) {
            $parts = $this->font->hasGlyph($g->codepoint) ? null : $this->decompose($g->codepoint);
            foreach ($parts ?? [$g->codepoint] as $k => $cp) {
                $copy = $k === 0 ? $g : clone $g;
                $copy->codepoint = $cp;
                $out[] = $copy;
            }
        }
        $this->buf = $out;

        foreach ($this->buf as $g) {
            if (Unicode::isMark($g->codepoint)) {
                $this->reorderMarks($arabic);
                $this->recompose();

                return;
            }
        }
    }

    /** @return list<int>|null code points the font has, or null if it cannot be split that way */
    private function decompose(int $cp): ?array
    {
        $parts = Unicode::decomposition($cp);
        if ($parts === null || (isset($parts[1]) && ! $this->font->hasGlyph($parts[1]))) {
            return null;
        }
        if ($this->font->hasGlyph($parts[0])) {
            return $parts;
        }
        $inner = $this->decompose($parts[0]);

        return $inner === null ? null : [...$inner, ...array_slice($parts, 1)];
    }

    /** Sorts each mark sequence by HarfBuzz's modified combining class. The sort is stable. */
    private function reorderMarks(bool $arabic): void
    {
        $n = count($this->buf);
        for ($i = 0; $i < $n; $i++) {
            if (Unicode::modifiedCombiningClass($this->buf[$i]->codepoint) === 0) {
                continue;
            }
            $end = $i + 1;
            while ($end < $n && Unicode::modifiedCombiningClass($this->buf[$end]->codepoint) !== 0) {
                $end++;
            }
            if ($end - $i <= self::MAX_MARKS) {
                $run = array_slice($this->buf, $i, $end - $i);
                usort($run, fn (Glyph $a, Glyph $b) => Unicode::modifiedCombiningClass($a->codepoint) <=> Unicode::modifiedCombiningClass($b->codepoint));
                array_splice($this->buf, $i, $end - $i, $run);
                if ($arabic) {
                    $this->moveModifierMarks($i, $end);
                }
            }
            $i = $end;
        }
    }

    /** Moves Arabic modifier combining marks to the start of their class (220 or 230). */
    private function moveModifierMarks(int $start, int $end): void
    {
        $class = fn (int $k) => Unicode::modifiedCombiningClass($this->buf[$k]->codepoint);
        $i = $start;
        foreach ([220, 230] as $cc) {
            while ($i < $end && $class($i) < $cc) {
                $i++;
            }
            if ($i === $end) {
                break;
            }
            if ($class($i) > $cc) {
                continue;
            }
            $j = $i;
            while ($j < $end && $class($j) === $cc && in_array($this->buf[$j]->codepoint, self::MODIFIER_MARKS, true)) {
                $j++;
            }
            if ($i === $j) {
                continue;
            }
            array_splice($this->buf, $start, 0, array_splice($this->buf, $i, $j - $i));
            $start += $j - $i;
            $i = $j;
        }
    }

    /** Recomposes a base character and a following mark when the font has the composed character. */
    private function recompose(): void
    {
        $out = [$this->buf[0]];
        $starter = 0;
        for ($k = 1, $n = count($this->buf); $k < $n; $k++) {
            $g = $this->buf[$k];
            $class = Unicode::modifiedCombiningClass($g->codepoint);
            if (Unicode::isMark($g->codepoint)) {
                $previous = Unicode::modifiedCombiningClass(end($out)->codepoint);
                if (($starter === count($out) - 1 || $previous < $class)
                    && ($composed = Unicode::compose($out[$starter]->codepoint, $g->codepoint)) !== null
                    && $this->font->hasGlyph($composed)) {
                    $out[$starter]->codepoint = $composed;
                    $out[$starter]->cluster = min($out[$starter]->cluster, $g->cluster);

                    continue;
                }
                if ($starter < count($out) - 1 && $previous > $class) {
                    $starter = count($out);
                }
            }
            $out[] = $g;
            if ($class === 0) {
                $starter = count($out) - 1;
            }
        }
        $this->buf = $out;
    }

    /** Assigns each Arabic letter its positional form: isolated, initial, medial or final. */
    private function setJoiningMasks(): void
    {
        $prev = null;
        $state = 0;
        foreach ($this->buf as $i => $g) {
            $type = Unicode::joiningType($g->codepoint);
            if ($type === 'T') {
                continue;
            }
            [$prevAction, $action, $state] = self::JOINING_STATES[$state][self::JOINING_COLUMNS[$type]];
            if ($prevAction !== null && $prev !== null) {
                $this->buf[$prev]->mask = self::GLOBAL | self::ARABIC_MASKS[$prevAction];
            }
            $g->mask = self::GLOBAL | ($action === null ? 0 : self::ARABIC_MASKS[$action]);
            $prev = $i;
        }
    }

    /** @return array{gsub: list<array<int, int>>, gpos: array<int, int>} lookups => feature masks */
    private function plan(string $scriptTag, bool $arabic): array
    {
        $key = $scriptTag.($arabic ? '+arabic' : '');
        if (isset($this->plans[$key])) {
            return $this->plans[$key];
        }

        $gsubStages = [];
        if ($gsub = $this->font->gsub()) {
            $stages = $arabic ? self::ARABIC_GSUB : self::DEFAULT_GSUB;
            $featureLookups = $gsub->featureLookups($scriptTag, self::LANGUAGE, array_merge(...$stages));
            foreach ($stages as $features) {
                $stage = [];
                foreach ($features as $feature) {
                    foreach ($featureLookups[$feature] ?? [] as $lookup) {
                        $stage[$lookup] = ($stage[$lookup] ?? 0) | (self::ARABIC_MASKS[$feature] ?? self::GLOBAL);
                    }
                }
                ksort($stage);
                if ($stage !== []) {
                    $gsubStages[] = $stage;
                }
            }
        }

        $gposLookups = [];
        if ($gpos = $this->font->gpos()) {
            foreach ($gpos->featureLookups($scriptTag, self::LANGUAGE, self::GPOS_FEATURES) as $lookups) {
                foreach ($lookups as $lookup) {
                    $gposLookups[$lookup] = self::GLOBAL;
                }
            }
            ksort($gposLookups);
        }

        return $this->plans[$key] = ['gsub' => $gsubStages, 'gpos' => $gposLookups];
    }

    private function glyphProps(int $glyph): int
    {
        return match ($this->font->glyphClass($glyph)) {
            1 => 0x02,
            2 => 0x04,
            3 => 0x08 | ($this->font->markAttachClass($glyph) << 8),
            default => 0,
        };
    }

    /** Returns true when the lookup flags cause this glyph to be skipped, for example IgnoreMarks. */
    private function ignored(Glyph $g, int $lookupProps): bool
    {
        if ($g->props & $lookupProps & 0x0E) {
            return true;
        }
        if ($g->props & 0x08) {
            if ($lookupProps & 0x10) {
                return ! $this->font->inMarkSet($lookupProps >> 16, $g->id);
            }
            if ($lookupProps & 0xFF00) {
                return ($lookupProps & 0xFF00) !== ($g->props & 0xFF00);
            }
        }

        return false;
    }

    private function nextIndex(int $i, int $props): ?int
    {
        for ($j = $i + 1, $n = count($this->buf); $j < $n; $j++) {
            if (! $this->ignored($this->buf[$j], $props)) {
                return $j;
            }
        }

        return null;
    }

    private function prevIndex(int $i, int $props): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (! $this->ignored($this->buf[$j], $props)) {
                return $j;
            }
        }

        return null;
    }

    /** Applies one lookup to the whole buffer, to glyphs that carry the feature mask. */
    private function applyLookup(LayoutTable $table, int $index, int $mask): void
    {
        $lookup = $table->lookup($index);
        $i = 0;
        while ($i < count($this->buf)) {
            $g = $this->buf[$i];
            if (($g->mask & $mask) && ! $this->ignored($g, $lookup['props'])) {
                $next = $this->applyAt($table, $lookup, $i, $mask);
                if ($next !== null) {
                    $i = $next;

                    continue;
                }
            }
            $i++;
        }
    }

    /**
     * Tries each subtable of the lookup at position $i.
     *
     * @param  array{type: int, props: int, subtables: list<array<string, mixed>>}  $lookup
     * @return int|null where to continue, or null if nothing applied
     */
    private function applyAt(LayoutTable $table, array $lookup, int $i, int $mask): ?int
    {
        foreach ($lookup['subtables'] as $sub) {
            $next = $table->isGpos
                ? $this->applyGpos($table, $lookup['type'], $sub, $lookup['props'], $i, $mask)
                : $this->applyGsub($table, $lookup['type'], $sub, $lookup['props'], $i, $mask);
            if ($next !== null) {
                return $next;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $sub */
    private function applyGsub(LayoutTable $table, int $type, array $sub, int $props, int $i, int $mask): ?int
    {
        $g = $this->buf[$i];
        switch ($type) {
            case 1:
                if (! isset($sub['map'][$g->id])) {
                    return null;
                }
                $g->id = $sub['map'][$g->id];
                $g->props = $this->glyphProps($g->id);

                return $i + 1;
            case 2:
                $sequence = $sub['map'][$g->id] ?? null;
                if ($sequence === null) {
                    return null;
                }
                $glyphs = [];
                foreach ($sequence as $k => $id) {
                    $copy = clone $g;
                    [$copy->id, $copy->props, $copy->ligComp] = [$id, $this->glyphProps($id), $k + 1];
                    $glyphs[] = $copy;
                }
                array_splice($this->buf, $i, 1, $glyphs);

                return $i + count($glyphs);
            case 4:
                foreach ($sub['map'][$g->id] ?? [] as [$ligature, $components]) {
                    $positions = [$i];
                    $j = $i;
                    foreach ($components as $component) {
                        $j = $this->nextIndex($j, $props);
                        if ($j === null || $this->buf[$j]->id !== $component || ! ($this->buf[$j]->mask & $mask)) {
                            continue 2;
                        }
                        $positions[] = $j;
                    }

                    return $i + 1 + $this->ligate($positions, $ligature);
                }

                return null;
            case 5:
            case 6:
                return $this->applyContext($table, $sub, $props, $i, $mask);
            default:
                return null;
        }
    }

    /**
     * Replaces the glyphs at $positions with a ligature glyph. Marks between
     * the components are kept after the ligature and record the component
     * they belong to.
     *
     * @param  list<int>  $positions
     * @return int how many marks now follow the ligature
     */
    private function ligate(array $positions, int $ligature): int
    {
        $first = $positions[0];
        $last = end($positions);
        $ligId = $this->nextLigId++;
        $cluster = min(array_map(fn ($k) => $this->buf[$k]->cluster, range($first, $last)));
        // As in HarfBuzz merge_clusters: following marks that share the last
        // component's cluster are merged into the ligature's cluster.
        for ($k = $last + 1; $k < count($this->buf) && $this->buf[$k]->cluster === $this->buf[$last]->cluster; $k++) {
            $this->buf[$k]->cluster = $cluster;
        }

        $component = 1;
        for ($k = $first + 1; $k <= $last; $k++) {
            $this->buf[$k]->cluster = $cluster;
            if (in_array($k, $positions, true)) {
                $component++;
            } else {
                [$this->buf[$k]->ligId, $this->buf[$k]->ligComp] = [$ligId, $component];
            }
        }
        $g = $this->buf[$first];
        [$g->id, $g->props, $g->cluster, $g->ligId, $g->ligComp] = [$ligature, $this->glyphProps($ligature), $cluster, $ligId, 0];
        foreach (array_reverse(array_slice($positions, 1)) as $k) {
            array_splice($this->buf, $k, 1);
        }

        return ($last - $first) - (count($positions) - 1);
    }

    /** @param  array<string, mixed>  $sub */
    private function applyGpos(LayoutTable $table, int $type, array $sub, int $props, int $i, int $mask): ?int
    {
        $g = $this->buf[$i];
        switch ($type) {
            case 1:
                if (! isset($sub['map'][$g->id])) {
                    return null;
                }
                self::addValue($g, $sub['map'][$g->id]);

                return $i + 1;
            case 2:
                if (! isset($sub['coverage'][$g->id])) {
                    return null;
                }
                $j = $this->nextIndex($i, $props);
                if ($j === null || ! ($this->buf[$j]->mask & $mask)) {
                    return null;
                }
                $second = $this->buf[$j];
                if ($sub['format'] === 1) {
                    $values = $table->pairSet($sub, $sub['coverage'][$g->id])[$second->id] ?? null;
                } else {
                    $class1 = $sub['class1'][$g->id] ?? 0;
                    $class2 = $sub['class2'][$second->id] ?? 0;
                    $values = $class1 < $sub['class1Count'] && $class2 < $sub['class2Count']
                        ? $table->pairClassValues($sub, $class1, $class2)
                        : null;
                }
                if ($values === null) {
                    return null;
                }
                self::addValue($g, $values[0]);
                self::addValue($second, $values[1]);

                return $sub['size2'] > 0 ? $j + 1 : $j;
            case 4:
            case 5:
                $mark = $sub['markCoverage'][$g->id] ?? null;
                if ($mark === null) {
                    return null;
                }
                // The base is the nearest preceding glyph that is not a mark.
                $j = $i - 1;
                while ($j >= 0 && ($this->buf[$j]->props & 0x08)) {
                    $j--;
                }
                $base = $j >= 0 ? ($sub['baseCoverage'][$this->buf[$j]->id] ?? null) : null;
                if ($base === null) {
                    return null;
                }
                if ($type === 4) {
                    $anchors = $sub['bases'][$base] ?? [];
                } else {
                    $components = $sub['ligatures'][$base] ?? [];
                    if ($components === []) {
                        return null;
                    }
                    $lig = $this->buf[$j];
                    $count = count($components);
                    $component = ($lig->ligId && $lig->ligId === $g->ligId && $g->ligComp > 0) ? min($count, $g->ligComp) - 1 : $count - 1;
                    $anchors = $components[$component];
                }

                return $this->attachMark($sub['marks'][$mark], $anchors, $i, $j);
            case 6:
                $mark = $sub['markCoverage'][$g->id] ?? null;
                if ($mark === null) {
                    return null;
                }
                $j = $this->prevIndex($i, $props & ~0x0E);
                if ($j === null || ! ($this->buf[$j]->props & 0x08)) {
                    return null;
                }
                $other = $this->buf[$j];
                $sameBase = $g->ligId === $other->ligId
                    ? ($g->ligId === 0 || $g->ligComp === $other->ligComp)
                    : (($g->ligId > 0 && ! $g->ligComp) || ($other->ligId > 0 && ! $other->ligComp));
                $mark2 = $sameBase ? ($sub['baseCoverage'][$other->id] ?? null) : null;
                if ($mark2 === null) {
                    return null;
                }

                return $this->attachMark($sub['marks'][$mark], $sub['bases'][$mark2] ?? [], $i, $j);
            case 3:
                $index = $sub['coverage'][$g->id] ?? null;
                $entry = $index === null ? null : $sub['entryExit'][$index][0];
                $j = $entry === null ? null : $this->prevIndex($i, $props);
                $previous = $j === null ? null : ($sub['coverage'][$this->buf[$j]->id] ?? null);
                $exit = $previous === null ? null : $sub['entryExit'][$previous][1];
                if ($exit === null) {
                    return null;
                }
                $this->attachCursive($j, $i, $exit, $entry, $props);

                return $i + 1;
            case 7:
            case 8:
                return $this->applyContext($table, $sub, $props, $i, $mask);
            default:
                return null;
        }
    }

    /**
     * Connects the exit anchor of glyph $i to the entry anchor of glyph $j,
     * as in HarfBuzz CursivePosFormat1. Used by Nastaliq and calligraphic fonts.
     *
     * @param  array{int, int}  $exit
     * @param  array{int, int}  $entry
     */
    private function attachCursive(int $i, int $j, array $exit, array $entry, int $props): void
    {
        [$a, $b] = [$this->buf[$i], $this->buf[$j]];
        if ($this->rtl) {
            $d = $exit[0] + $a->xOffset;
            $a->xAdvance -= $d;
            $a->xOffset -= $d;
            $b->xAdvance = $entry[0] + $b->xOffset;
        } else {
            $a->xAdvance = $exit[0] + $a->xOffset;
            $d = $entry[0] + $b->xOffset;
            $b->xAdvance -= $d;
            $b->xOffset -= $d;
        }

        // The child glyph is shifted vertically to meet its parent. With the
        // RightToLeft lookup flag, the glyph later in the text is the child.
        [$child, $parent, $yOffset] = ($props & 1) ? [$i, $j, $entry[1] - $exit[1]] : [$j, $i, $exit[1] - $entry[1]];
        $this->reverseCursiveChain($child, $parent);
        $c = $this->buf[$child];
        [$c->attachType, $c->attachChain, $c->yOffset] = [2, $parent - $child, $yOffset];
        $p = $this->buf[$parent];
        if ($p->attachChain === -$c->attachChain) {
            [$p->attachChain, $p->yOffset] = [0, 0];
        }
    }

    /** Reverses an existing cursive chain so that it attaches to the new parent. */
    private function reverseCursiveChain(int $i, int $newParent): void
    {
        $g = $this->buf[$i];
        [$chain, $type] = [$g->attachChain, $g->attachType];
        if ($chain === 0 || $type !== 2) {
            return;
        }
        $g->attachChain = 0;
        $j = $i + $chain;
        if ($j === $newParent) {
            return;
        }
        $this->reverseCursiveChain($j, $newParent);
        $next = $this->buf[$j];
        [$next->yOffset, $next->attachChain, $next->attachType] = [-$g->yOffset, -$chain, $type];
    }

    /** @param  array{int, int, int}  $value  [x placement, y placement, x advance] */
    private static function addValue(Glyph $g, array $value): void
    {
        $g->xOffset += $value[0];
        $g->yOffset += $value[1];
        $g->xAdvance += $value[2];
    }

    /**
     * @param  array{int, int, int}  $mark  [mark class, anchor x, anchor y]
     * @param  array<int, array{int, int}|null>  $anchors  base anchors by mark class
     */
    private function attachMark(array $mark, array $anchors, int $i, int $base): ?int
    {
        $anchor = $anchors[$mark[0]] ?? null;
        if ($anchor === null) {
            return null;
        }
        $g = $this->buf[$i];
        $g->xOffset = $anchor[0] - $mark[1];
        $g->yOffset = $anchor[1] - $mark[2];
        [$g->attachChain, $g->attachType] = [$base - $i, 1];

        return $i + 1;
    }

    /**
     * Applies a context rule at position $i when the surrounding glyphs
     * match, running the rule's nested lookups.
     *
     * @param  array<string, mixed>  $sub
     */
    private function applyContext(LayoutTable $table, array $sub, int $props, int $i, int $mask): ?int
    {
        $g = $this->buf[$i];
        if (! isset($sub['coverage'][$g->id])) {
            return null;
        }
        $format = $sub['context'];
        $rules = match ($format) {
            1 => $sub['sets'][$sub['coverage'][$g->id]] ?? [],
            2 => $sub['sets'][$sub['classDefs'][1][$g->id] ?? 0] ?? [],
            default => $sub['rules'],
        };

        foreach ($rules as [$backtrack, $input, $lookahead, $records]) {
            $positions = [$i];
            $j = $i;
            foreach ($format === 3 ? array_slice($input, 1) : $input as $want) {
                $j = $this->nextIndex($j, $props);
                if ($j === null || ! ($this->buf[$j]->mask & $mask) || ! $this->matches($sub, 1, $want, $this->buf[$j]->id)) {
                    continue 2;
                }
                $positions[] = $j;
            }
            $k = $i;
            foreach ($backtrack as $want) {
                $k = $this->prevIndex($k, $props);
                if ($k === null || ! $this->matches($sub, 0, $want, $this->buf[$k]->id)) {
                    continue 2;
                }
            }
            $k = $j;
            foreach ($lookahead as $want) {
                $k = $this->nextIndex($k, $props);
                if ($k === null || ! $this->matches($sub, 2, $want, $this->buf[$k]->id)) {
                    continue 2;
                }
            }

            return $this->applyNested($table, $records, $positions, $mask);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $sub
     * @param  int|array<int, int>  $want  A glyph ID (format 1), a class (format 2) or a coverage (format 3).
     */
    private function matches(array $sub, int $which, int|array $want, int $glyph): bool
    {
        return match ($sub['context']) {
            1 => $want === $glyph,
            2 => ($sub['classDefs'][$which][$glyph] ?? 0) === $want,
            default => isset($want[$glyph]),
        };
    }

    /**
     * Applies the nested lookups of a matched context rule. When a nested
     * lookup inserts or removes glyphs, the matched positions are renumbered
     * as in HarfBuzz, so later records may refer to inserted glyphs.
     *
     * @param  list<array{int, int}>  $records  [sequence index, lookup index]
     * @param  list<int>  $positions  buffer positions of the matched input glyphs
     * @return int where to continue: after the matched input
     */
    private function applyNested(LayoutTable $table, array $records, array $positions, int $mask): int
    {
        $end = end($positions) + 1;
        foreach ($records as [$index, $lookupIndex]) {
            $count = count($positions);
            $before = count($this->buf);
            if ($index >= $count || $positions[$index] >= $before) {
                continue;
            }
            if ($this->applyAt($table, $table->lookup($lookupIndex), $positions[$index], $mask) === null) {
                continue;
            }
            $delta = count($this->buf) - $before;
            if ($delta === 0) {
                continue;
            }
            $end += $delta;
            if ($end < $positions[$index]) {
                $delta += $positions[$index] - $end;
                $end = $positions[$index];
            }
            $next = $index + 1;
            if ($delta > 0) {
                $added = range($positions[$index] + 1, $positions[$index] + $delta);
                $rest = array_map(fn ($p) => $p + $delta, array_slice($positions, $next));
                $positions = [...array_slice($positions, 0, $next), ...$added, ...$rest];
            } else {
                $delta = max($delta, $next - $count);
                $rest = array_map(fn ($p) => $p + $delta, array_slice($positions, $next - $delta));
                $positions = [...array_slice($positions, 0, $next), ...$rest];
            }
        }

        return $end;
    }

    /**
     * Applies kerning from the legacy kern table as HarfBuzz does: half of
     * the value is added to the first glyph's advance and the remainder to
     * the second glyph's advance and offset.
     */
    private function applyLegacyKerning(): void
    {
        $i = 0;
        while (($j = $this->nextIndex($i, 0x08)) !== null) {
            $kern = $this->font->legacyKerning($this->buf[$i]->id, $this->buf[$j]->id);
            if ($kern !== 0) {
                $first = $kern >> 1;
                $this->buf[$i]->xAdvance += $first;
                $this->buf[$j]->xAdvance += $kern - $first;
                $this->buf[$j]->xOffset += $kern - $first;
            }
            $i = $j;
        }
    }

    /** Final positioning steps from HarfBuzz: zero-width marks, hidden default ignorables and attachment offsets. */
    private function finishPositions(bool $rtl): void
    {
        $space = $this->font->glyphId(0x20);
        foreach ($this->buf as $g) {
            if ($g->props & 0x08) {
                $g->xAdvance = 0;
            }
            if (Unicode::isDefaultIgnorable($g->codepoint)) {
                [$g->id, $g->xAdvance] = [$space, 0];
            }
        }
        foreach (array_keys($this->buf) as $i) {
            $this->propagateAttachment($i, $rtl, 8);
        }
    }

    /**
     * Converts attachment offsets so that they are relative to the glyph's
     * own pen position. Cursive attachments inherit the vertical offset of
     * their parent; marks inherit the full offset of their base.
     */
    private function propagateAttachment(int $i, bool $rtl, int $depth): void
    {
        $g = $this->buf[$i];
        $chain = $g->attachChain;
        if ($chain === 0 || $depth === 0) {
            return;
        }
        $g->attachChain = 0;
        $j = $i + $chain;
        if ($j < 0 || $j >= count($this->buf)) {
            return;
        }
        $this->propagateAttachment($j, $rtl, $depth - 1);
        if ($g->attachType === 2) {
            $g->yOffset += $this->buf[$j]->yOffset;

            return;
        }
        $g->xOffset += $this->buf[$j]->xOffset;
        $g->yOffset += $this->buf[$j]->yOffset;
        if ($rtl) {
            for ($k = $j + 1; $k <= $i; $k++) {
                $g->xOffset += $this->buf[$k]->xAdvance;
            }
        } else {
            for ($k = $j; $k < $i; $k++) {
                $g->xOffset -= $this->buf[$k]->xAdvance;
            }
        }
    }
}
