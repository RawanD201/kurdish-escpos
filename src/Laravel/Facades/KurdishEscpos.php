<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Rawand\KurdishEscpos\Receipt receipt()
 * @method static \Rawand\KurdishEscpos\RenderedReceipt render(\Rawand\KurdishEscpos\Receipt|array<string, mixed>|string $receipt)
 * @method static \Rawand\KurdishEscpos\RenderedReceipt print(\Rawand\KurdishEscpos\Receipt|array<string, mixed>|string $receipt, ?\Rawand\KurdishEscpos\Printers\Printer $printer = null)
 * @method static \Rawand\KurdishEscpos\Renderer renderer()
 * @method static ?\Rawand\KurdishEscpos\Printers\Printer printer()
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
