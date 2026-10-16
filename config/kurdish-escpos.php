<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Font
    |--------------------------------------------------------------------------
    |
    | Path to a TrueType (.ttf) font. When empty, the bundled Vazirmatn font
    | is used. Characters missing from the configured font are drawn with
    | Vazirmatn. Verify a font with: php artisan kurdish-escpos:check-font
    |
    */
    'font' => env('KURDISH_ESCPOS_FONT'),

    /*
    |--------------------------------------------------------------------------
    | Paper width
    |--------------------------------------------------------------------------
    |
    | Printable width in dots: 576 for 80 mm paper, 384 for 58 mm paper.
    |
    */
    'width' => (int) env('KURDISH_ESCPOS_WIDTH', 576),

    /*
    |--------------------------------------------------------------------------
    | Margin
    |--------------------------------------------------------------------------
    |
    | Horizontal margin in dots. When null, the renderer default of 8 is used.
    |
    */
    'margin' => null,

    /*
    |--------------------------------------------------------------------------
    | Default printer
    |--------------------------------------------------------------------------
    |
    | Set "host" for a network printer (most models listen on port 9100), or
    | "path" for a printer exposed as a file, such as /dev/usb/lp0 on Linux or
    | \\localhost\XPrinter on Windows. Leave both empty to pass a printer
    | explicitly when printing.
    |
    */
    'printer' => [
        'host' => env('KURDISH_ESCPOS_PRINTER_HOST'),
        'port' => (int) env('KURDISH_ESCPOS_PRINTER_PORT', 9100),
        'path' => env('KURDISH_ESCPOS_PRINTER_PATH'),
        'timeout' => 10,
    ],

];
