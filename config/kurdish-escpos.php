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
    | The name of the printer used when you print without naming one.
    |
    */
    'default' => env('KURDISH_ESCPOS_PRINTER', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Printers
    |--------------------------------------------------------------------------
    |
    | Each printer has a name. Set "host" for a network printer (most models
    | listen on port 9100), or "path" for a printer exposed as a file, such as
    | /dev/usb/lp0 on Linux or \\localhost\XPrinter on Windows.
    |
    | Every printer can also have its own settings:
    |
    | "width"       Printable width in dots; the receipt is laid out for it.
    | "font"        Path to a .ttf font.
    | "image"       Picture command: "raster" works on most printers; use
    |               "column" for older printers that print nothing or symbols.
    | "cut"         "partial", "full" or "none" (no cutter).
    | "feed"        Lines of paper fed before the cut.
    | "drawer_pin"  Cash drawer connector pin: 2 or 5.
    | "language"    "escpos" for receipt printers, "zpl" for Zebra or "tspl"
    |               for TSC label printers.
    |
    | Label printers (Zebra and TSC) also accept:
    |
    | "label_height" Label length in dots; leave it out for continuous paper.
    | "label_gap"    TSC only: gap between labels in mm (default 2).
    | "darkness"     0-30 for Zebra, 0-15 for TSC; leave it out to keep the
    |                printer's own setting.
    | "dpi"          203, 300 or 600 (default 203).
    |
    */
    'printers' => [

        'default' => [
            'host' => env('KURDISH_ESCPOS_PRINTER_HOST'),
            'port' => (int) env('KURDISH_ESCPOS_PRINTER_PORT', 9100),
            'path' => env('KURDISH_ESCPOS_PRINTER_PATH'),
            'timeout' => 10,
            'language' => env('KURDISH_ESCPOS_LANGUAGE', 'escpos'),
            'image' => env('KURDISH_ESCPOS_IMAGE', 'raster'),
            'cut' => env('KURDISH_ESCPOS_CUT', 'partial'),
            'feed' => 4,
            'drawer_pin' => 2,
        ],

        // 'kitchen' => [
        //     'host' => '192.168.1.51',
        //     'width' => 384,
        // ],

        // 'bar' => [
        //     'path' => '/dev/usb/lp0',
        //     'image' => 'column',
        //     'cut' => 'none',
        // ],

        // 'labels' => [
        //     'host' => '192.168.1.60',
        //     'language' => 'zpl',
        //     'width' => 812,          // 4 inch labels at 203 dpi
        //     'label_height' => 1218,  // 6 inch labels
        // ],

    ],

];
