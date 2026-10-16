<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Text;

/**
 * Implementation of the Unicode Bidirectional Algorithm (UAX #9), which
 * determines the visual order of right-to-left and left-to-right text.
 *
 * Explicit embedding and isolate controls (U+202A..U+202E, U+2066..U+2069)
 * are treated as neutral characters.
 *
 * @internal
 */
final class Bidi
{
    /**
     * Resolves the embedding level of each character. Even levels are
     * left-to-right and odd levels are right-to-left.
     *
     * @param  list<int>  $cps
     * @return array{levels: list<int>, classes: list<string>} levels and the original bidi classes
     */
    public static function analyze(array $cps, int $paragraphLevel): array
    {
        $classes = [];
        foreach ($cps as $cp) {
            $class = Unicode::bidiClass($cp);
            $classes[] = match ($class) {
                'LRE', 'RLE', 'LRO', 'RLO', 'PDF' => 'BN',
                'LRI', 'RLI', 'FSI', 'PDI' => 'ON',
                default => $class,
            };
        }

        // X9: boundary neutrals are excluded and later receive the level of
        // the preceding character.
        $index = [];
        foreach ($classes as $i => $class) {
            if ($class !== 'BN') {
                $index[] = $i;
            }
        }
        $t = array_map(fn ($i) => $classes[$i], $index);
        $n = count($t);
        $e = ($paragraphLevel & 1) ? 'R' : 'L';

        // W1: a non-spacing mark takes the type of the preceding character.
        for ($k = 0; $k < $n; $k++) {
            if ($t[$k] === 'NSM') {
                $t[$k] = $k === 0 ? $e : $t[$k - 1];
            }
        }
        // W2, W3: European numbers after Arabic letters become Arabic numbers; AL becomes R.
        $lastStrong = $e;
        for ($k = 0; $k < $n; $k++) {
            if (in_array($t[$k], ['L', 'R', 'AL'], true)) {
                $lastStrong = $t[$k];
            } elseif ($t[$k] === 'EN' && $lastStrong === 'AL') {
                $t[$k] = 'AN';
            }
        }
        foreach ($t as $k => $type) {
            if ($type === 'AL') {
                $t[$k] = 'R';
            }
        }
        // W4: a single separator between two numbers of the same type joins them.
        for ($k = 1; $k < $n - 1; $k++) {
            if ($t[$k] === 'ES' && $t[$k - 1] === 'EN' && $t[$k + 1] === 'EN') {
                $t[$k] = 'EN';
            } elseif ($t[$k] === 'CS' && $t[$k - 1] === $t[$k + 1] && ($t[$k - 1] === 'EN' || $t[$k - 1] === 'AN')) {
                $t[$k] = $t[$k - 1];
            }
        }
        // W5: terminators adjacent to European numbers become European numbers.
        for ($k = 0; $k < $n; $k++) {
            if ($t[$k] !== 'ET') {
                continue;
            }
            $end = $k;
            while ($end < $n && $t[$end] === 'ET') {
                $end++;
            }
            if (($k > 0 && $t[$k - 1] === 'EN') || ($end < $n && $t[$end] === 'EN')) {
                for ($m = $k; $m < $end; $m++) {
                    $t[$m] = 'EN';
                }
            }
            $k = $end - 1;
        }
        // W6, W7.
        $lastStrong = $e;
        for ($k = 0; $k < $n; $k++) {
            if ($t[$k] === 'ES' || $t[$k] === 'ET' || $t[$k] === 'CS') {
                $t[$k] = 'ON';
            }
            if ($t[$k] === 'L' || $t[$k] === 'R') {
                $lastStrong = $t[$k];
            } elseif ($t[$k] === 'EN' && $lastStrong === 'L') {
                $t[$k] = 'L';
            }
        }

        self::resolveBrackets($t, array_map(fn ($i) => $cps[$i], $index), array_map(fn ($i) => $classes[$i], $index), $e);

        // N1, N2: neutrals between runs of the same direction take that
        // direction; all other neutrals take the embedding direction.
        $strong = fn (string $type) => match ($type) {
            'L' => 'L',
            'R', 'EN', 'AN' => 'R',
            default => null,
        };
        for ($k = 0; $k < $n; $k++) {
            if ($strong($t[$k]) !== null) {
                continue;
            }
            $end = $k;
            while ($end < $n && $strong($t[$end]) === null) {
                $end++;
            }
            $before = $k > 0 ? $strong($t[$k - 1]) : $e;
            $after = $end < $n ? $strong($t[$end]) : $e;
            $resolved = $before === $after ? $before : $e;
            for ($m = $k; $m < $end; $m++) {
                $t[$m] = $resolved;
            }
            $k = $end - 1;
        }

        // I1, I2.
        $levels = array_fill(0, count($cps), $paragraphLevel);
        foreach ($index as $k => $i) {
            $levels[$i] = match (true) {
                ($paragraphLevel & 1) === 0 && $t[$k] === 'R' => $paragraphLevel + 1,
                ($paragraphLevel & 1) === 0 && ($t[$k] === 'AN' || $t[$k] === 'EN') => $paragraphLevel + 2,
                ($paragraphLevel & 1) === 1 && $t[$k] !== 'R' => $paragraphLevel + 1,
                default => $paragraphLevel,
            };
        }
        for ($i = 1, $count = count($cps); $i < $count; $i++) {
            if ($classes[$i] === 'BN') {
                $levels[$i] = $levels[$i - 1];
            }
        }

        return ['levels' => $levels, 'classes' => $classes];
    }

    /**
     * Rule L1: resets segment separators and trailing whitespace on a line
     * to the paragraph level.
     *
     * @param  list<int>  $levels  levels of the line's characters
     * @param  list<string>  $classes  original bidi classes of the same characters
     * @return list<int>
     */
    public static function lineLevels(array $levels, array $classes, int $paragraphLevel): array
    {
        $trailing = true;
        for ($i = count($levels) - 1; $i >= 0; $i--) {
            $class = $classes[$i];
            if ($class === 'S' || $class === 'B') {
                $levels[$i] = $paragraphLevel;
                $trailing = true;
            } elseif ($trailing && ($class === 'WS' || $class === 'BN')) {
                $levels[$i] = $paragraphLevel;
            } else {
                $trailing = false;
            }
        }

        return $levels;
    }

    /**
     * Rule L2: returns the visual order, from left to right, of items with
     * the given levels.
     *
     * @param  list<int>  $levels
     * @return list<int> indices into $levels
     */
    public static function visualOrder(array $levels): array
    {
        $order = array_keys($levels);
        if ($levels === []) {
            return $order;
        }
        $lowestOdd = min(array_map(fn ($l) => $l | 1, $levels));
        for ($level = max($levels); $level >= $lowestOdd; $level--) {
            for ($i = 0, $n = count($levels); $i < $n; $i++) {
                if ($levels[$order[$i]] < $level) {
                    continue;
                }
                $end = $i;
                while ($end < $n && $levels[$order[$end]] >= $level) {
                    $end++;
                }
                array_splice($order, $i, $end - $i, array_reverse(array_slice($order, $i, $end - $i)));
                $i = $end;
            }
        }

        return $order;
    }

    /**
     * Rule N0: resolves paired brackets to the direction of their content,
     * so that text such as "(500 mg)" stays together.
     *
     * @param  array<int, string>  $t  Resolved types, updated in place.
     * @param  array<int, int>  $cps
     * @param  array<int, string>  $original
     */
    private static function resolveBrackets(array &$t, array $cps, array $original, string $e): void
    {
        $pairs = [];
        $stack = [];
        foreach ($cps as $k => $cp) {
            $bracket = $t[$k] === 'ON' ? Unicode::bracket($cp) : null;
            if ($bracket === null) {
                continue;
            }
            if ($bracket[1] === 'o') {
                if (count($stack) === 63) {
                    break;
                }
                $stack[] = [$bracket[0], $k];
            } else {
                for ($s = count($stack) - 1; $s >= 0; $s--) {
                    if ($stack[$s][0] === $cp) {
                        $pairs[] = [$stack[$s][1], $k];
                        array_splice($stack, $s);
                        break;
                    }
                }
            }
        }
        usort($pairs, fn ($a, $b) => $a[0] <=> $b[0]);

        $strong = fn (string $type) => match ($type) {
            'L' => 'L',
            'R', 'EN', 'AN' => 'R',
            default => null,
        };
        $opposite = $e === 'L' ? 'R' : 'L';
        foreach ($pairs as [$open, $close]) {
            $inside = null;
            for ($k = $open + 1; $k < $close; $k++) {
                $s = $strong($t[$k]);
                if ($s === $e) {
                    $inside = $e;
                    break;
                }
                $inside ??= $s;
            }
            if ($inside === null) {
                continue;
            }
            if ($inside !== $e) {
                $before = $e;
                for ($k = $open - 1; $k >= 0; $k--) {
                    if (($s = $strong($t[$k])) !== null) {
                        $before = $s;
                        break;
                    }
                }
                $inside = $before === $opposite ? $opposite : $e;
            }
            foreach ([$open, $close] as $k) {
                $t[$k] = $inside;
                for ($m = $k + 1; $m < count($t) && $original[$m] === 'NSM'; $m++) {
                    $t[$m] = $inside;
                }
            }
        }
    }
}
