<?php

declare(strict_types=1);

namespace RawanD201\KurdishEscpos\Text;

/**
 * A line of shaped text ready for drawing. Glyph positions are in dots,
 * relative to the left end of the baseline.
 */
final class Line
{
    /**
     * @param  list<array{int, float, float, int, Font}>  $glyphs  [glyph ID, x, y offset upward, index of its first character, font]
     * @param  float  $width  in dots
     * @param  bool  $rtl  the paragraph direction
     * @param  float  $size  font size in dots
     */
    public function __construct(
        public readonly array $glyphs,
        public readonly float $width,
        public readonly bool $rtl,
        public readonly float $size,
        public readonly float $ascent,
        public readonly float $descent,
    ) {}

    public function height(): float
    {
        return $this->ascent + $this->descent;
    }
}
