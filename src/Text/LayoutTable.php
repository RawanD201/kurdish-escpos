<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Text;

/**
 * A GSUB (glyph substitution) or GPOS (glyph positioning) table. These
 * tables contain the font's shaping rules: contextual letter forms,
 * ligatures, kerning and mark placement.
 *
 * Lookups are parsed on first use.
 *
 * @internal
 */
final class LayoutTable
{
    /** @var array<string, array{default: list<int>|null, required: int|null, langs: array<string, array{list<int>, int|null}>}> */
    private array $scripts = [];

    /** @var list<array{string, list<int>}> feature index => [tag, lookup indices] */
    private array $features = [];

    /** @var list<int> */
    private array $lookupOffsets = [];

    /** @var array<int, array{type: int, props: int, subtables: list<array<string, mixed>>}> */
    private array $lookups = [];

    /** @var array<int, array<int, array<int, mixed>>> PairPos subtable offset => coverage index => pairs */
    private array $pairSets = [];

    public function __construct(private readonly string $d, private readonly int $base, public readonly bool $isGpos)
    {
        $scriptList = $base + Bytes::u16($d, $base + 4);
        $featureList = $base + Bytes::u16($d, $base + 6);
        $lookupList = $base + Bytes::u16($d, $base + 8);

        for ($i = 0, $n = Bytes::u16($d, $scriptList); $i < $n; $i++) {
            $record = $scriptList + 2 + 6 * $i;
            $script = $scriptList + Bytes::u16($d, $record + 4);
            $entry = ['default' => null, 'required' => null, 'langs' => []];
            if ($default = Bytes::u16($d, $script)) {
                [$entry['default'], $entry['required']] = $this->readLangSys($script + $default);
            }
            for ($j = 0, $m = Bytes::u16($d, $script + 2); $j < $m; $j++) {
                $langRecord = $script + 4 + 6 * $j;
                $entry['langs'][substr($d, $langRecord, 4)] = $this->readLangSys($script + Bytes::u16($d, $langRecord + 4));
            }
            $this->scripts[substr($d, $record, 4)] = $entry;
        }

        for ($i = 0, $n = Bytes::u16($d, $featureList); $i < $n; $i++) {
            $record = $featureList + 2 + 6 * $i;
            $feature = $featureList + Bytes::u16($d, $record + 4);
            $this->features[] = [substr($d, $record, 4), Bytes::u16Array($d, $feature + 4, Bytes::u16($d, $feature + 2))];
        }

        $count = Bytes::u16($d, $lookupList);
        foreach (Bytes::u16Array($d, $lookupList + 2, $count) as $offset) {
            $this->lookupOffsets[] = $lookupList + $offset;
        }
    }

    /**
     * Returns the lookups of each requested feature for one script and
     * language. The script falls back to DFLT, then latn; the language falls
     * back to the script's default language system.
     *
     * @param  list<string>  $features
     * @return array<string, list<int>> feature tag => lookup indices
     */
    public function featureLookups(string $script, string $language, array $features): array
    {
        $entry = $this->scripts[$script] ?? $this->scripts['DFLT'] ?? $this->scripts['dflt'] ?? $this->scripts['latn'] ?? null;
        if ($entry === null) {
            return [];
        }
        [$indices, $required] = $entry['langs'][$language] ?? [$entry['default'] ?? [], $entry['required']];

        $wanted = array_flip($features);
        $out = [];
        foreach ($indices as $index) {
            [$tag, $lookups] = $this->features[$index] ?? ['', []];
            if (isset($wanted[$tag])) {
                $out[$tag] = array_merge($out[$tag] ?? [], $lookups);
            }
        }
        if ($required !== null && isset($this->features[$required])) {
            [$tag, $lookups] = $this->features[$required];
            $out[$tag] = array_merge($out[$tag] ?? [], $lookups);
        }

        return $out;
    }

    /** Returns true when the feature is defined for any script. */
    public function hasFeature(string $tag): bool
    {
        foreach ($this->features as [$featureTag]) {
            if ($featureTag === $tag) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the lookup types used by the given features. Used to detect
     * fonts that rely on unsupported lookup types.
     *
     * @param  list<string>  $features
     * @return list<int>
     */
    public function lookupTypes(string $script, string $language, array $features): array
    {
        $types = [];
        foreach ($this->featureLookups($script, $language, $features) as $lookups) {
            foreach ($lookups as $index) {
                $types[$this->lookup($index)['type']] = true;
            }
        }
        ksort($types);

        return array_keys($types);
    }

    /** @return array{type: int, props: int, subtables: list<array<string, mixed>>} */
    public function lookup(int $index): array
    {
        return $this->lookups[$index] ??= $this->readLookup($index);
    }

    public function lookupCount(): int
    {
        return count($this->lookupOffsets);
    }

    /**
     * Reads PairPos format 2 values for one class pair on demand.
     *
     * @param  array<string, mixed>  $subtable
     * @return array{array{int, int, int}, array{int, int, int}}
     */
    public function pairClassValues(array $subtable, int $class1, int $class2): array
    {
        $record = $subtable['records'] + ($class1 * $subtable['class2Count'] + $class2) * ($subtable['size1'] + $subtable['size2']);

        return [
            self::readValue($this->d, $record, $subtable['format1']),
            self::readValue($this->d, $record + $subtable['size1'], $subtable['format2']),
        ];
    }

    /**
     * Reads the PairPos format 1 pair set for one first glyph on demand.
     *
     * @param  array<string, mixed>  $subtable
     * @return array<int, array{array{int, int, int}, array{int, int, int}}> second glyph => values
     */
    public function pairSet(array $subtable, int $coverageIndex): array
    {
        $o = $subtable['offset'];
        if (isset($this->pairSets[$o][$coverageIndex])) {
            return $this->pairSets[$o][$coverageIndex];
        }
        $d = $this->d;
        $set = $o + Bytes::u16($d, $o + 10 + 2 * $coverageIndex);
        $size = 2 + $subtable['size1'] + $subtable['size2'];
        $pairs = [];
        for ($i = 0, $n = Bytes::u16($d, $set); $i < $n; $i++) {
            $p = $set + 2 + $size * $i;
            $pairs[Bytes::u16($d, $p)] = [
                self::readValue($d, $p + 2, $subtable['format1']),
                self::readValue($d, $p + 2 + $subtable['size1'], $subtable['format2']),
            ];
        }

        return $this->pairSets[$o][$coverageIndex] = $pairs;
    }

    /** @return array<int, int> glyph => coverage index */
    public static function readCoverage(string $d, int $o): array
    {
        $map = [];
        $format = Bytes::u16($d, $o);
        $count = Bytes::u16($d, $o + 2);
        if ($format === 1) {
            foreach (Bytes::u16Array($d, $o + 4, $count) as $i => $glyph) {
                $map[$glyph] = $i;
            }
        } elseif ($format === 2) {
            for ($i = 0; $i < $count; $i++) {
                $r = $o + 4 + 6 * $i;
                $start = Bytes::u16($d, $r);
                $end = Bytes::u16($d, $r + 2);
                $index = Bytes::u16($d, $r + 4);
                for ($g = $start; $g <= $end; $g++) {
                    $map[$g] = $index++;
                }
            }
        }

        return $map;
    }

    /** @return array<int, int> glyph => class (glyphs not listed are class 0) */
    public static function readClassDef(string $d, int $o): array
    {
        $map = [];
        $format = Bytes::u16($d, $o);
        if ($format === 1) {
            $start = Bytes::u16($d, $o + 2);
            foreach (Bytes::u16Array($d, $o + 6, Bytes::u16($d, $o + 4)) as $i => $class) {
                if ($class !== 0) {
                    $map[$start + $i] = $class;
                }
            }
        } elseif ($format === 2) {
            for ($i = 0, $n = Bytes::u16($d, $o + 2); $i < $n; $i++) {
                $r = $o + 4 + 6 * $i;
                $class = Bytes::u16($d, $r + 4);
                for ($g = Bytes::u16($d, $r), $end = Bytes::u16($d, $r + 2); $g <= $end; $g++) {
                    $map[$g] = $class;
                }
            }
        }

        return $map;
    }

    /** @return array{list<int>, int|null} feature indices and the required feature */
    private function readLangSys(int $o): array
    {
        $required = Bytes::u16($this->d, $o + 2);

        return [Bytes::u16Array($this->d, $o + 6, Bytes::u16($this->d, $o + 4)), $required === 0xFFFF ? null : $required];
    }

    /** @return array{type: int, props: int, subtables: list<array<string, mixed>>} */
    private function readLookup(int $index): array
    {
        $d = $this->d;
        $o = $this->lookupOffsets[$index] ?? null;
        if ($o === null) {
            return ['type' => 0, 'props' => 0, 'subtables' => []];
        }
        $type = Bytes::u16($d, $o);
        $flag = Bytes::u16($d, $o + 2);
        $count = Bytes::u16($d, $o + 4);
        // UseMarkFilteringSet: the set index follows the subtable offsets.
        $props = ($flag & 0x10) ? $flag | (Bytes::u16($d, $o + 6 + 2 * $count) << 16) : $flag;

        // Extension lookups (GSUB 7, GPOS 9) wrap subtables of another type.
        $subtables = [];
        $realType = $type;
        foreach (Bytes::u16Array($d, $o + 6, $count) as $offset) {
            $sub = $o + $offset;
            if ($type === ($this->isGpos ? 9 : 7)) {
                $realType = Bytes::u16($d, $sub + 2);
                $sub += Bytes::u32($d, $sub + 4);
            }
            $subtables[] = $this->isGpos ? $this->readGpos($realType, $sub) : $this->readGsub($realType, $sub);
        }

        return ['type' => $realType, 'props' => $props, 'subtables' => $subtables];
    }

    /** @return array<string, mixed> */
    private function readGsub(int $type, int $o): array
    {
        $d = $this->d;
        $format = Bytes::u16($d, $o);
        $coverage = fn () => self::readCoverage($d, $o + Bytes::u16($d, $o + 2));

        switch ($type) {
            case 1: // single substitution
                $map = [];
                if ($format === 1) {
                    $delta = Bytes::i16($d, $o + 4);
                    foreach ($coverage() as $glyph => $i) {
                        $map[$glyph] = ($glyph + $delta) & 0xFFFF;
                    }
                } else {
                    $substitutes = Bytes::u16Array($d, $o + 6, Bytes::u16($d, $o + 4));
                    foreach ($coverage() as $glyph => $i) {
                        $map[$glyph] = $substitutes[$i];
                    }
                }

                return ['map' => $map];
            case 2: // multiple substitution
            case 3: // alternate substitution, not used by default features
                $map = [];
                foreach ($coverage() as $glyph => $i) {
                    $seq = $o + Bytes::u16($d, $o + 6 + 2 * $i);
                    $map[$glyph] = Bytes::u16Array($d, $seq + 2, Bytes::u16($d, $seq));
                }

                return ['map' => $map];
            case 4: // ligature substitution
                $map = [];
                foreach ($coverage() as $glyph => $i) {
                    $set = $o + Bytes::u16($d, $o + 6 + 2 * $i);
                    foreach (Bytes::u16Array($d, $set + 2, Bytes::u16($d, $set)) as $offset) {
                        $lig = $set + $offset;
                        $map[$glyph][] = [Bytes::u16($d, $lig), Bytes::u16Array($d, $lig + 4, Bytes::u16($d, $lig + 2) - 1)];
                    }
                }

                return ['map' => $map];
            case 5:
                return $this->readContext($o, false);
            case 6:
                return $this->readContext($o, true);
            default: // 8, reverse chaining substitution, is not supported
                return [];
        }
    }

    /** @return array<string, mixed> */
    private function readGpos(int $type, int $o): array
    {
        $d = $this->d;
        $format = Bytes::u16($d, $o);
        $coverage = fn (int $at = 2) => self::readCoverage($d, $o + Bytes::u16($d, $o + $at));

        switch ($type) {
            case 1: // single adjustment
                $valueFormat = Bytes::u16($d, $o + 4);
                $size = self::valueSize($valueFormat);
                $map = [];
                foreach ($coverage() as $glyph => $i) {
                    $map[$glyph] = self::readValue($d, $format === 1 ? $o + 6 : $o + 8 + $size * $i, $valueFormat);
                }

                return ['map' => $map];
            case 2: // pair adjustment
                $format1 = Bytes::u16($d, $o + 4);
                $format2 = Bytes::u16($d, $o + 6);
                $common = ['format' => $format, 'coverage' => $coverage(), 'format1' => $format1, 'format2' => $format2,
                    'size1' => self::valueSize($format1), 'size2' => self::valueSize($format2)];
                if ($format === 1) {
                    return $common + ['offset' => $o];
                }

                return $common + [
                    'class1' => self::readClassDef($d, $o + Bytes::u16($d, $o + 8)),
                    'class2' => self::readClassDef($d, $o + Bytes::u16($d, $o + 10)),
                    'class1Count' => Bytes::u16($d, $o + 12),
                    'class2Count' => Bytes::u16($d, $o + 14),
                    'records' => $o + 16,
                ];
            case 4: // mark to base
            case 6: // mark to mark
                $classCount = Bytes::u16($d, $o + 6);

                return [
                    'markCoverage' => $coverage(2),
                    'baseCoverage' => $coverage(4),
                    'marks' => $this->readMarkArray($o + Bytes::u16($d, $o + 8)),
                    'bases' => $this->readAnchorMatrix($o + Bytes::u16($d, $o + 10), $classCount),
                ];
            case 5: // mark to ligature
                $classCount = Bytes::u16($d, $o + 6);
                $ligArray = $o + Bytes::u16($d, $o + 10);
                $ligatures = [];
                for ($i = 0, $n = Bytes::u16($d, $ligArray); $i < $n; $i++) {
                    $ligatures[] = $this->readAnchorMatrix($ligArray + Bytes::u16($d, $ligArray + 2 + 2 * $i), $classCount);
                }

                return [
                    'markCoverage' => $coverage(2),
                    'baseCoverage' => $coverage(4),
                    'marks' => $this->readMarkArray($o + Bytes::u16($d, $o + 8)),
                    'ligatures' => $ligatures,
                ];
            case 3: // cursive attachment
                $records = [];
                for ($i = 0, $n = Bytes::u16($d, $o + 4); $i < $n; $i++) {
                    $entry = Bytes::u16($d, $o + 6 + 4 * $i);
                    $exit = Bytes::u16($d, $o + 8 + 4 * $i);
                    $records[$i] = [$entry ? $this->readAnchor($o + $entry) : null, $exit ? $this->readAnchor($o + $exit) : null];
                }

                return ['coverage' => $coverage(), 'entryExit' => $records];
            case 7:
                return $this->readContext($o, false);
            case 8:
                return $this->readContext($o, true);
            default:
                return [];
        }
    }

    /** @return array<int, array{int, int, int}> mark coverage index => [class, x, y] */
    private function readMarkArray(int $o): array
    {
        $marks = [];
        for ($i = 0, $n = Bytes::u16($this->d, $o); $i < $n; $i++) {
            $r = $o + 2 + 4 * $i;
            [$x, $y] = $this->readAnchor($o + Bytes::u16($this->d, $r + 2));
            $marks[$i] = [Bytes::u16($this->d, $r), $x, $y];
        }

        return $marks;
    }

    /** @return list<array<int, array{int, int}|null>> row => mark class => anchor */
    private function readAnchorMatrix(int $o, int $classCount): array
    {
        $rows = [];
        for ($i = 0, $n = Bytes::u16($this->d, $o); $i < $n; $i++) {
            $row = [];
            for ($c = 0; $c < $classCount; $c++) {
                $anchor = Bytes::u16($this->d, $o + 2 + 2 * ($i * $classCount + $c));
                $row[$c] = $anchor ? $this->readAnchor($o + $anchor) : null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array{int, int} */
    private function readAnchor(int $o): array
    {
        return [Bytes::i16($this->d, $o + 2), Bytes::i16($this->d, $o + 4)];
    }

    private static function valueSize(int $format): int
    {
        return 2 * substr_count(decbin($format & 0xFF), '1');
    }

    /** @return array{int, int, int} [x placement, y placement, x advance] (device tables ignored) */
    private static function readValue(string $d, int $o, int $format): array
    {
        $value = [0, 0, 0];
        foreach ([0x1 => 0, 0x2 => 1, 0x4 => 2] as $bit => $slot) {
            if ($format & $bit) {
                $value[$slot] = Bytes::i16($d, $o);
                $o += 2;
            }
        }

        return $value;
    }

    /**
     * Reads a context subtable (GSUB 5/6, GPOS 7/8) into one structure for
     * all three formats. Each rule holds matchers for the backtrack (nearest
     * first), the input (including the first glyph) and the lookahead, and
     * the nested lookups to apply.
     *
     * @return array<string, mixed>
     */
    private function readContext(int $o, bool $chained): array
    {
        $d = $this->d;
        $format = Bytes::u16($d, $o);

        if ($format === 3) {
            $p = $o + 2;
            $read = function () use ($d, $o, &$p) {
                $count = Bytes::u16($d, $p);
                $covs = [];
                foreach (Bytes::u16Array($d, $p + 2, $count) as $offset) {
                    $covs[] = self::readCoverage($d, $o + $offset);
                }
                $p += 2 + 2 * $count;

                return $covs;
            };
            if ($chained) {
                $backtrack = $read();
                $input = $read();
                $lookahead = $read();
                $records = $this->readRecords($p + 2, Bytes::u16($d, $p));
            } else {
                $inputCount = Bytes::u16($d, $p);
                $recordCount = Bytes::u16($d, $p + 2);
                $input = [];
                foreach (Bytes::u16Array($d, $p + 4, $inputCount) as $offset) {
                    $input[] = self::readCoverage($d, $o + $offset);
                }
                [$backtrack, $lookahead] = [[], []];
                $records = $this->readRecords($p + 4 + 2 * $inputCount, $recordCount);
            }

            return ['context' => 3, 'coverage' => $input[0] ?? [], 'rules' => [[$backtrack, $input, $lookahead, $records]]];
        }

        $coverage = self::readCoverage($d, $o + Bytes::u16($d, $o + 2));
        $classDefs = null;
        if ($format === 2) {
            $offsets = $chained ? [4, 6, 8] : [null, 4, null];
            $classDefs = array_map(fn ($at) => $at === null ? [] : self::readClassDef($d, $o + Bytes::u16($d, $o + $at)), $offsets);
            $setsAt = $chained ? 10 : 6;
        } else {
            $setsAt = 4;
        }

        // Rule sets are indexed by coverage index (format 1) or by input
        // class (format 2). They are small, so all of them are read at once.
        $sets = [];
        for ($i = 0, $n = Bytes::u16($d, $o + $setsAt); $i < $n; $i++) {
            $setOffset = Bytes::u16($d, $o + $setsAt + 2 + 2 * $i);
            if ($setOffset === 0) {
                continue;
            }
            $set = $o + $setOffset;
            foreach (Bytes::u16Array($d, $set + 2, Bytes::u16($d, $set)) as $ruleOffset) {
                $r = $set + $ruleOffset;
                if ($chained) {
                    $backtrack = Bytes::u16Array($d, $r + 2, $bn = Bytes::u16($d, $r));
                    $r += 2 + 2 * $bn;
                    $input = Bytes::u16Array($d, $r + 2, ($in = Bytes::u16($d, $r)) - 1);
                    $r += 2 * $in;
                    $lookahead = Bytes::u16Array($d, $r + 2, $ln = Bytes::u16($d, $r));
                    $r += 2 + 2 * $ln;
                    $records = $this->readRecords($r + 2, Bytes::u16($d, $r));
                } else {
                    $in = Bytes::u16($d, $r);
                    $input = Bytes::u16Array($d, $r + 4, $in - 1);
                    [$backtrack, $lookahead] = [[], []];
                    $records = $this->readRecords($r + 4 + 2 * ($in - 1), Bytes::u16($d, $r + 2));
                }
                $sets[$i][] = [$backtrack, $input, $lookahead, $records];
            }
        }

        return ['context' => $format, 'coverage' => $coverage, 'classDefs' => $classDefs, 'sets' => $sets];
    }

    /** @return list<array{int, int}> [sequence index, lookup index] */
    private function readRecords(int $o, int $count): array
    {
        $records = [];
        for ($i = 0; $i < $count; $i++) {
            $records[] = [Bytes::u16($this->d, $o + 4 * $i), Bytes::u16($this->d, $o + 4 * $i + 2)];
        }

        return $records;
    }
}
