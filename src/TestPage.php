<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos;

/**
 * A printer test page: Kurdish, Arabic and Latin text, digits, an item row,
 * a QR code, and the words "right" and "left" at the two edges of the paper
 * to check the configured width.
 */
final class TestPage
{
    public static function receipt(int $width = Receipt::WIDTH_80MM, string $details = ''): Receipt
    {
        $receipt = Receipt::make($width)
            ->title('kurdish-escpos', 34)
            ->text('تاقیکردنەوەی پرینتەر', 28, 'center')
            ->divider()
            ->row('ڕاست', 'چەپ', 26)
            ->text('ئەگەر «ڕاست» یان «چەپ» دیار نییە، پانی (width) کەم بکەرەوە.', 20, 'center')
            ->text('If a word at the edge is cut off, lower the width.', 18, 'center')
            ->divider()
            ->text('کوڵێرە، هەڵەبجە، باڵندە، ئێوارە، پێنج', 24)
            ->text('السلام عليكم', 24)
            ->text('Hêvî û şahî, spas!', 24)
            ->text('0123456789  ٠١٢٣٤٥٦٧٨٩', 24, 'center')
            ->divider()
            ->row('کۆی گشتی', '16,750 دینار', 30)
            ->qr('https://github.com/RawanD201/kurdish-escpos', 160);

        return $details === '' ? $receipt : $receipt->text($details, 18, 'center');
    }
}
