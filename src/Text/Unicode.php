<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Text;

/**
 * Unicode character properties, read from resources/unicode.php, which is
 * derived from the Unicode Character Database, version 16.0.
 *
 * @internal
 */
final class Unicode
{
    /** @var array<string, mixed>|null */
    private static ?array $tables = null;

    /** @var array<string, array<int, mixed>> */
    private static array $memo = [];

    /** Returns the bidi class, for example L, R, AL, EN, AN, WS or ON. */
    public static function bidiClass(int $cp): string
    {
        return self::$memo['bidi'][$cp] ??= self::find('bidi', $cp, 'L');
    }

    /** Returns the Arabic joining type: U, L, R, D (including join-causing) or T (transparent). */
    public static function joiningType(int $cp): string
    {
        return self::$memo['joining'][$cp] ??= self::find('joining', $cp, 'U');
    }

    /** Returns the canonical combining class, which is 0 for base characters. */
    public static function combiningClass(int $cp): int
    {
        return self::$memo['combining'][$cp] ??= self::find('combining', $cp, 0);
    }

    /**
     * Returns the combining class HarfBuzz uses to order marks. It equals the
     * Unicode class, except that Arabic shadda (33) sorts before other vowel marks.
     */
    public static function modifiedCombiningClass(int $cp): int
    {
        $class = self::combiningClass($cp);

        return match (true) {
            $class === 33 => 27,
            $class >= 27 && $class <= 32 => $class + 1,
            default => $class,
        };
    }

    /** @return list<int>|null The one-step canonical decomposition, for example ş to s and U+0327. */
    public static function decomposition(int $cp): ?array
    {
        return self::tables()['decompose'][$cp] ?? null;
    }

    /** Returns the precomposed character for a base and a mark, or null. */
    public static function compose(int $base, int $mark): ?int
    {
        return self::tables()['compose'][$base][$mark] ?? null;
    }

    /** Returns true for combining marks (general category Mn, Mc or Me). */
    public static function isMark(int $cp): bool
    {
        return self::$memo['mark'][$cp] ??= (bool) self::find('mark', $cp, 0);
    }

    /** Returns the script: arab, latn, cyrl, grek, common, inherited or other. */
    public static function script(int $cp): string
    {
        return self::$memo['script'][$cp] ??= self::find('script', $cp, 'other');
    }

    /** Returns the mirrored character for right-to-left text, for example ")" for "(". */
    public static function mirror(int $cp): ?int
    {
        return self::tables()['mirror'][$cp] ?? null;
    }

    /** @return array{int, string}|null The paired bracket and its type, "o" (opening) or "c" (closing). */
    public static function bracket(int $cp): ?array
    {
        return self::tables()['brackets'][$cp] ?? null;
    }

    /** Returns true for default-ignorable formatting characters, such as the zero-width non-joiner. */
    public static function isDefaultIgnorable(int $cp): bool
    {
        return $cp === 0x00AD || $cp === 0x034F || $cp === 0x061C
            || ($cp >= 0x200B && $cp <= 0x200F) || ($cp >= 0x202A && $cp <= 0x202E)
            || ($cp >= 0x2060 && $cp <= 0x206F) || ($cp >= 0xFE00 && $cp <= 0xFE0F) || $cp === 0xFEFF;
    }

    /** @return list<int> */
    public static function codepoints(string $text): array
    {
        if (! preg_match('//u', $text)) {
            throw new \InvalidArgumentException('Text is not valid UTF-8.');
        }
        $cps = [];
        for ($i = 0, $n = strlen($text); $i < $n;) {
            $b = ord($text[$i]);
            if ($b < 0x80) {
                $cps[] = $b;
                $i++;
            } elseif ($b < 0xE0) {
                $cps[] = (($b & 0x1F) << 6) | (ord($text[$i + 1]) & 0x3F);
                $i += 2;
            } elseif ($b < 0xF0) {
                $cps[] = (($b & 0x0F) << 12) | ((ord($text[$i + 1]) & 0x3F) << 6) | (ord($text[$i + 2]) & 0x3F);
                $i += 3;
            } else {
                $cps[] = (($b & 0x07) << 18) | ((ord($text[$i + 1]) & 0x3F) << 12) | ((ord($text[$i + 2]) & 0x3F) << 6) | (ord($text[$i + 3]) & 0x3F);
                $i += 4;
            }
        }

        return $cps;
    }

    private static function find(string $table, int $cp, mixed $default): mixed
    {
        $ranges = self::tables()[$table];
        $lo = 0;
        $hi = count($ranges) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($cp < $ranges[$mid][0]) {
                $hi = $mid - 1;
            } elseif ($cp > $ranges[$mid][1]) {
                $lo = $mid + 1;
            } else {
                return $ranges[$mid][2];
            }
        }

        return $default;
    }

    /** @return array<string, mixed> */
    private static function tables(): array
    {
        return self::$tables ??= require dirname(__DIR__, 2).'/resources/unicode.php';
    }
}
