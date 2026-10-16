<?php

declare(strict_types=1);

namespace RawanD201\KurdishEscpos\Text;

/**
 * Reads big-endian integers from a binary string, as stored in font files.
 *
 * @internal
 */
final class Bytes
{
    public static function u16(string $d, int $o): int
    {
        return (ord($d[$o]) << 8) | ord($d[$o + 1]);
    }

    public static function i16(string $d, int $o): int
    {
        $v = (ord($d[$o]) << 8) | ord($d[$o + 1]);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    public static function u32(string $d, int $o): int
    {
        return (ord($d[$o]) << 24) | (ord($d[$o + 1]) << 16) | (ord($d[$o + 2]) << 8) | ord($d[$o + 3]);
    }

    public static function i8(string $d, int $o): int
    {
        $v = ord($d[$o]);

        return $v >= 0x80 ? $v - 0x100 : $v;
    }

    /** Reads a signed 2.14 fixed-point number, as used for composite glyph scales. */
    public static function f2dot14(string $d, int $o): float
    {
        return self::i16($d, $o) / 16384;
    }

    /** @return list<int> */
    public static function u16Array(string $d, int $o, int $count): array
    {
        if ($count === 0) {
            return [];
        }

        return array_values(unpack('n'.$count, $d, $o));
    }
}
