<?php

declare(strict_types=1);

namespace RawanD201\KurdishEscpos\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \RawanD201\KurdishEscpos\Receipt receipt(?string $printer = null)
 * @method static \RawanD201\KurdishEscpos\RenderedReceipt render(\RawanD201\KurdishEscpos\Receipt|array<string, mixed>|string $receipt, ?string $printer = null)
 * @method static \RawanD201\KurdishEscpos\PrintResult print(\RawanD201\KurdishEscpos\Receipt|array<string, mixed>|string $receipt, \RawanD201\KurdishEscpos\Printers\Printer|string|array<array-key, \RawanD201\KurdishEscpos\Printers\Printer|string>|null $to = null)
 * @method static \RawanD201\KurdishEscpos\PrintResult printEach(array<string, \RawanD201\KurdishEscpos\Receipt|array<string, mixed>|string> $receipts)
 * @method static \RawanD201\KurdishEscpos\Renderer renderer()
 * @method static ?\RawanD201\KurdishEscpos\Printers\Printer printer(?string $name = null)
 * @method static list<string> printerNames()
 *
 * @see \RawanD201\KurdishEscpos\KurdishEscpos
 */
class KurdishEscpos extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \RawanD201\KurdishEscpos\KurdishEscpos::class;
    }
}
