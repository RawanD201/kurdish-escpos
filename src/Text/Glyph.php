<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Text;

/**
 * A glyph in a shaped run. Positions and advances are in font units.
 */
final class Glyph
{
    public function __construct(
        /** Glyph ID in the font. */
        public int $id,
        /** Index of the first source character that produced this glyph. */
        public int $cluster,
        public int $codepoint = 0,
        /** Feature mask: bit flags selecting the features applied to this glyph. */
        public int $mask = 0,
        /** GDEF class as lookup flag bits: base 0x02, ligature 0x04, mark 0x08. */
        public int $props = 0,
        public int $ligId = 0,
        public int $ligComp = 0,
        public int $xAdvance = 0,
        public int $xOffset = 0,
        public int $yOffset = 0,
        /** Offset to the glyph this glyph is attached to, or 0. */
        public int $attachChain = 0,
        /** Attachment type: 1 for mark attachment, 2 for cursive attachment. */
        public int $attachType = 0,
    ) {}
}
