<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Fluent builder for a receipt, rendered by {@see Renderer}.
 *
 *     $receipt = Receipt::make()
 *         ->title('دەرمانخانەی ئازادی')
 *         ->row('پاراسیتامۆڵ', '3,000')
 *         ->divider()
 *         ->row('کۆی گشتی', '3,000 دینار', size: 32)
 *         ->qr('https://example.com/r/1042');
 */
final class Receipt implements JsonSerializable
{
    /** Printable width of 80 mm paper, in dots at 203 dpi. */
    public const WIDTH_80MM = 576;

    /** Printable width of 58 mm paper, in dots at 203 dpi. */
    public const WIDTH_58MM = 384;

    private const ALIGNMENTS = ['start', 'center', 'end'];

    /** @var list<array<string, mixed>> */
    private array $items = [];

    /**
     * @param  int  $width  Printable width in dots (576 for 80 mm paper, 384 for 58 mm).
     * @param  int|null  $margin  Horizontal margin in dots; the renderer default is 8.
     */
    public function __construct(
        private readonly int $width = self::WIDTH_80MM,
        private readonly ?int $margin = null,
    ) {
        if ($width <= 0) {
            throw new InvalidArgumentException("Width must be positive, got {$width}.");
        }
    }

    public static function make(int $width = self::WIDTH_80MM, ?int $margin = null): self
    {
        return new self($width, $margin);
    }

    /**
     * Adds a paragraph of text. Long lines wrap, and "\n" starts a new paragraph.
     *
     * @param  string  $align  "start", "center" or "end". "start" is the right edge for
     *                         right-to-left text and the left edge for left-to-right text.
     */
    public function text(string $text, ?float $size = null, string $align = 'start'): self
    {
        if (! in_array($align, self::ALIGNMENTS, true)) {
            throw new InvalidArgumentException("Align must be start, center or end, got \"{$align}\".");
        }

        return $this->add(['type' => 'text', 'text' => $text, 'size' => $size, 'align' => $align]);
    }

    /** Adds large, centered text, such as the name of the shop. */
    public function title(string $text, float $size = 40): self
    {
        return $this->text($text, $size, 'center');
    }

    /**
     * Adds an item row. The name starts the line (on the right for Kurdish and
     * Arabic, on the left for Latin text) and the price is at the other end.
     */
    public function row(string $name, string $price, ?float $size = null): self
    {
        return $this->add(['type' => 'row', 'name' => $name, 'price' => $price, 'size' => $size]);
    }

    /** Adds a dashed horizontal rule. */
    public function divider(): self
    {
        return $this->add(['type' => 'divider']);
    }

    /**
     * Adds a centered QR code.
     *
     * @param  int|null  $size  Approximate width in dots; the renderer default is 240.
     */
    public function qr(string $data, ?int $size = null): self
    {
        if ($data === '') {
            throw new InvalidArgumentException('QR data must not be empty.');
        }

        return $this->add(['type' => 'qr', 'data' => $data, 'size' => $size]);
    }

    /** Adds vertical space, measured in lines. */
    public function feed(int $lines = 1): self
    {
        if ($lines < 1) {
            throw new InvalidArgumentException("Lines must be at least 1, got {$lines}.");
        }

        return $this->add(['type' => 'feed', 'lines' => $lines]);
    }

    /** @return array{width: int, margin?: int, items: list<array<string, mixed>>} */
    public function toArray(): array
    {
        $receipt = ['width' => $this->width];
        if ($this->margin !== null) {
            $receipt['margin'] = $this->margin;
        }
        $receipt['items'] = $this->items;

        return $receipt;
    }

    /** Encodes the receipt as JSON, keeping non-ASCII text as UTF-8. */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array{width: int, margin?: int, items: list<array<string, mixed>>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param  array<string, mixed>  $item */
    private function add(array $item): self
    {
        $this->items[] = array_filter($item, fn ($value) => $value !== null);

        return $this;
    }
}
