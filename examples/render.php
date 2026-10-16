<?php

// Renders examples/receipt.json to examples/receipt.png (the preview) and
// examples/receipt.bin (the ESC/POS command stream).
//
//   php examples/render.php                 # bundled Vazirmatn font
//   php examples/render.php /path/font.ttf  # a custom TrueType font

declare(strict_types=1);

use Rawand\KurdishEscpos\Renderer;

require __DIR__.'/../vendor/autoload.php';

$start = microtime(true);
$rendered = (new Renderer($argv[1] ?? null))->render((string) file_get_contents(__DIR__.'/receipt.json'));

file_put_contents(__DIR__.'/receipt.png', $rendered->png);
file_put_contents(__DIR__.'/receipt.bin', $rendered->escpos);
printf("Saved examples/receipt.png and examples/receipt.bin (%d bytes) in %d ms\n", strlen($rendered->escpos), (microtime(true) - $start) * 1000);
