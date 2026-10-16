<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Rawand\KurdishEscpos\Receipt receipt(?string $printer = null)
 * @method static \Rawand\KurdishEscpos\RenderedReceipt render(\Rawand\KurdishEscpos\Receipt|array<string, mixed>|string $receipt, ?string $printer = null)
 * @method static \Rawand\KurdishEscpos\PrintResult print(\Rawand\KurdishEscpos\Receipt|array<string, mixed>|string $receipt, \Rawand\KurdishEscpos\Printers\Printer|string|array<array-key, \Rawand\KurdishEscpos\Printers\Printer|string>|null $to = null)
 * @method static \Rawand\KurdishEscpos\PrintResult printEach(array<string, \Rawand\KurdishEscpos\Receipt|array<string, mixed>|string> $receipts)
 * @method static \Rawand\KurdishEscpos\Renderer renderer()
 * @method static ?\Rawand\KurdishEscpos\Printers\Printer printer(?string $name = null)
 * @method static list<string> printerNames()
 *
 * @see \Rawand\KurdishEscpos\KurdishEscpos
 */
class KurdishEscpos extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Rawand\KurdishEscpos\KurdishEscpos::class;
    }
}
