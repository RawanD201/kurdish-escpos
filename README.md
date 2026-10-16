<p align="center">
  <img src="art/banner.png" alt="kurdish-escpos: print receipts in Sorani, Badini, Hawrami and every Kurdish dialect with pure PHP" width="100%">
</p>

<p align="center">
  <a href="https://packagist.org/packages/rawand201/kurdish-escpos"><img src="https://img.shields.io/packagist/v/rawand201/kurdish-escpos?label=packagist" alt="Latest version"></a>
  <a href="https://github.com/RawanD201/kurdish-escpos/actions/workflows/tests.yml"><img src="https://github.com/RawanD201/kurdish-escpos/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="https://packagist.org/packages/rawand201/kurdish-escpos"><img src="https://img.shields.io/packagist/dependency-v/rawand201/kurdish-escpos/php" alt="PHP version"></a>
  <a href="https://packagist.org/packages/rawand201/kurdish-escpos"><img src="https://img.shields.io/packagist/dt/rawand201/kurdish-escpos" alt="Total downloads"></a>
  <a href="LICENSE"><img src="https://img.shields.io/packagist/l/rawand201/kurdish-escpos" alt="License"></a>
</p>

# kurdish-escpos

Print receipts and labels in Kurdish, Arabic and English on thermal
printers, with PHP. It works with Sorani, Badini, Hawrami and the other Kurdish
dialects. Every letter joins the right way, and every line reads in the
right direction.

You only need PHP and the GD extension. There is nothing else to install, so
it also works on normal shared hosting.

<div dir="rtl">

**kurdish-escpos** پاکێجێکی PHPیە بۆ چاپکردنی وەسڵ بە کوردی (سۆرانی، بادینی، هەورامی و شێوەزارەکانی تر)، عەرەبی و ئینگلیزی لەسەر پرینتەری وەسڵ. پیتەکان بە دروستی پێکەوە دەلکێن و دەق بە ڕێکی لە ڕاستەوە بۆ چەپ دەنووسرێت. هیچ بەرنامەیەکی دەرەکی پێویست نییە؛ تەنها PHP و GD.

</div>

```php
use Rawand\KurdishEscpos\Laravel\Facades\KurdishEscpos;

KurdishEscpos::print(
    KurdishEscpos::receipt()
        ->title('دەرمانخانەی ئازادی')
        ->text('سلێمانی، شەقامی سالم', align: 'center')
        ->divider()
        ->row('کوڵێرە', '1,250')
        ->row('ڤیتامین C', '12,500')
        ->divider()
        ->row('کۆی گشتی', '13,750 دینار', size: 32)
        ->qr('https://example.com/r/1042')
);
```

<p align="center">
  <img src="art/receipt.png" alt="A receipt printed by the package" width="360">
</p>

## Contents

- [Why this package exists](#why-this-package-exists)
- [Features](#features)
- [Showcase](#showcase)
- [Installation](#installation)
- [Usage with Laravel](#usage-with-laravel)
- [Usage without Laravel](#usage-without-laravel)
- [Building receipts](#building-receipts)
- [Supported printers](#supported-printers)
- [Multiple printers](#multiple-printers)
- [Fonts](#fonts)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)

## Why this package exists

Receipt printers do not know Kurdish:

- They do not have the Kurdish letters ڕ, ڵ, ێ and ە.
- They do not join letters together.
- They do not write from right to left.

So when you send Kurdish text to a receipt printer, it prints wrong or
broken text.

Drawing the receipt as a picture with PHP does not help either. PHP's image
functions draw every letter on its own, so the letters do not join. Some PHP
libraries fix this for Arabic, but Kurdish letters like ڵ and ێ still come
out broken.

This package solves the problem. It joins every letter the right way, puts
the words in the right order, draws the receipt as a picture, and sends the
picture to the printer. Every receipt printer can print a picture, so your
Kurdish text always looks right.

## Features

- Every Kurdish letter joins correctly, even ڵ and ێ.
- Works with Sorani, Badini, Hawrami and the other Kurdish dialects.
- Kurdish, Arabic and English can be on the same line, and numbers and
  prices stay in the right place.
- Use the included font, or your own font, such as NRT or Rudaw.
- Titles, text, item rows, lines, QR codes and empty space, for 80 mm and
  58 mm paper and for labels.
- See a picture of the receipt before you print it.
- Works with receipt printers (ESC/POS) and with Zebra and TSC label
  printers.
- Print to network printers, USB printers and Windows shared printers.
- Ready for Laravel, and works in any PHP project.
- Only needs PHP and GD.

## Showcase

Receipts in Sorani, Badini, Hawrami, Gorani, Laki, Luri and Zazaki, each
printed by the package with a different font:

<p align="center">
  <img src="art/fonts.png" alt="Receipts in Sorani, Badini, Hawrami, Gorani, Laki, Luri and Zazaki printed by kurdish-escpos" width="100%">
</p>

## Installation

Requirements:

- PHP 8.2 or later
- The `gd` and `iconv` extensions, which are enabled on almost every PHP
  installation
- Laravel 11, 12 or 13, if you use the Laravel integration

Install the package with Composer:

```bash
composer require rawand201/kurdish-escpos
```

## Usage with Laravel

The service provider and the `KurdishEscpos` facade are registered
automatically. Configure your printer in `.env`:

```dotenv
KURDISH_ESCPOS_PRINTER_HOST=192.168.1.50   # network printer
# KURDISH_ESCPOS_PRINTER_PATH=/dev/usb/lp0 # or a printer exposed as a file
KURDISH_ESCPOS_WIDTH=576                   # 576 for 80 mm paper, 384 for 58 mm
```

To change other settings, publish the configuration file:

```bash
php artisan vendor:publish --tag=kurdish-escpos-config
```

Print a receipt:

```php
use Rawand\KurdishEscpos\Laravel\Facades\KurdishEscpos;

$receipt = KurdishEscpos::receipt()
    ->title('دەرمانخانەی ئازادی')
    ->row('پاراسیتامۆڵ', '3,000')
    ->row('کۆی گشتی', '3,000 دینار', size: 32);

KurdishEscpos::print($receipt);
```

To send a receipt to a printer other than the default one:

```php
use Rawand\KurdishEscpos\Printers\NetworkPrinter;

KurdishEscpos::print($receipt, new NetworkPrinter('192.168.1.60'));
```

Show a preview before printing, for example in a Blade view:

```php
$preview = KurdishEscpos::render($receipt)->pngDataUri();
```

```blade
<img src="{{ $preview }}" alt="Receipt preview">
```

### Printing from a queue

Rendering a receipt takes about 130 ms. To keep requests fast, print from a
queued job:

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Rawand\KurdishEscpos\Laravel\Facades\KurdishEscpos;

class PrintReceipt implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order) {}

    public function handle(): void
    {
        $receipt = KurdishEscpos::receipt()->title($this->order->shop_name);

        foreach ($this->order->items as $item) {
            $receipt->row($item->name, number_format($item->total));
        }

        KurdishEscpos::print($receipt);
    }
}
```

## Usage without Laravel

```php
use Rawand\KurdishEscpos\Printers\NetworkPrinter;
use Rawand\KurdishEscpos\Receipt;
use Rawand\KurdishEscpos\Renderer;

$receipt = Receipt::make()
    ->title('سوپاس')
    ->text('ڕۆژێکی خۆش', align: 'center')
    ->feed();

$rendered = (new Renderer)->render($receipt);

file_put_contents('preview.png', $rendered->png);
(new NetworkPrinter('192.168.1.50'))->send($rendered->commands());
```

## Building receipts

| Method | Description |
|---|---|
| `text($text, $size = null, $align = 'start')` | A paragraph. Long lines wrap, and `\n` starts a new paragraph. `start` is the right edge for Kurdish and Arabic and the left edge for English; `center` and `end` are also available. |
| `title($text, $size = 40)` | Large, centered text, such as the shop name. |
| `row($name, $price, $size = null)` | An item name and its price on one line. For Kurdish and Arabic the name is on the right and the price on the left; for Latin text it is the other way around. Long names wrap. |
| `divider()` | A dashed horizontal rule. |
| `qr($data, $size = null)` | A centered QR code, about 240 dots wide by default. |
| `feed($lines = 1)` | Vertical space. |
| `openDrawer()` | Opens the cash drawer after printing. |

Sizes are measured in printer dots, at 203 dots per inch. The default text
size is 24. For 58 mm paper, create the receipt with
`Receipt::make(Receipt::WIDTH_58MM)`.

A receipt can also be given as an array or as JSON, which is convenient when
receipts are produced by another system:

```json
{
  "width": 576,
  "items": [
    { "type": "text", "text": "دەرمانخانەی ئازادی", "size": 40, "align": "center" },
    { "type": "row", "name": "کوڵێرە", "price": "1,250" },
    { "type": "divider" },
    { "type": "qr", "data": "https://example.com/r/1042" },
    { "type": "feed", "lines": 1 }
  ]
}
```

Unknown fields and invalid values are reported with a clear error message
rather than ignored.

## Supported printers

This package works with almost every thermal receipt printer, and with
Zebra and TSC label printers. Your printer only needs to:

1. Speak one of these command languages:
   - **ESC/POS**, which almost all receipt printers use. It was made by
     Epson, and most other brands copied it. This is the default.
   - **ZPL**, used by Zebra label printers.
   - **TSPL**, used by TSC label printers and many other label printers.
     The printer's manual tells you if it supports TSPL.
2. Print pictures. The package sends each receipt as one picture, so the
   printer does not need to know Kurdish at all.

This includes most receipt printers sold in Kurdistan, such as Xprinter,
Epson TM, Rongta and HPRT models, and label printers such as the Zebra
ZD220, ZD421 and GK420 and the TSC TE200 and TTP-244.

These printers do **not** work:

- Very old Zebra printers that only speak EPL, such as the LP 2844.
- Star Micronics printers in Star mode. Many Star models can be switched to
  ESC/POS mode in their settings.
- Normal office printers (A4 laser or inkjet).

### Connecting the printer

| Connection | Class | Example |
|---|---|---|
| Ethernet or Wi-Fi | `NetworkPrinter` | `new NetworkPrinter('192.168.1.50')` |
| USB on Linux | `FilePrinter` | `new FilePrinter('/dev/usb/lp0')` |
| Shared printer on Windows | `FilePrinter` | `new FilePrinter('\\\\localhost\\XPrinter')` |
| Serial or Bluetooth on Linux | `FilePrinter` | `new FilePrinter('/dev/ttyUSB0')` |

Most network printers use port 9100. If yours uses another port, pass it as
the second argument.

`FilePrinter` only works when the printer is connected to the same computer
that runs PHP, and PHP must be allowed to write to it. On Linux, add the web
server user to the `lp` group. For a serial printer, set the speed first, for
example `stty -F /dev/ttyUSB0 9600`. A Bluetooth printer that is paired as a
serial port appears as `/dev/rfcomm0`.

### Paper width

Set the width to the number of dots your printer prints on one line:

| Paper | Width |
|---|---|
| 80 mm | 576 dots (some models, such as the Epson TM-T88, print 512) |
| 58 mm | 384 dots |

If you are not sure, print the printer's self-test page: turn the printer
off, hold the FEED button, and turn it on. The page shows the print width.

### Older printers

Some older printers do not understand the normal picture command. If your
printer prints nothing, or prints strange symbols, switch to the older
command:

```php
use Rawand\KurdishEscpos\EscPos\PrinterOptions;

$renderer = new Renderer(options: new PrinterOptions(image: 'column'));
```

In Laravel, set `KURDISH_ESCPOS_IMAGE=column` in `.env`.

### Cutting and the cash drawer

| Option | Values |
|---|---|
| `cut` | `partial` (default), `full`, or `none` for printers without a cutter |
| `feed` | Lines of paper fed before the cut (default 4) |
| `drawerPin` | The cash drawer pin: 2 (default) or 5 |

```php
$renderer = new Renderer(options: new PrinterOptions(cut: 'full'));
```

In Laravel, set `KURDISH_ESCPOS_CUT=full` in `.env`.

To open the cash drawer that is connected to the printer, call
`openDrawer()` on the receipt:

```php
KurdishEscpos::print($receipt->openDrawer());
```

### Label printers

Zebra and TSC printers print labels, such as price tags and pharmacy labels.
Tell the package which language your printer speaks:

```php
$renderer = new Renderer(options: new PrinterOptions(language: 'zpl'));   // Zebra
$renderer = new Renderer(options: new PrinterOptions(language: 'tspl'));  // TSC
```

In Laravel, set `KURDISH_ESCPOS_LANGUAGE=zpl` (or `tspl`) in `.env`.

Set the width to the width of your labels, in dots:

| Label width | 203 dpi printer | 300 dpi printer |
|---|---|---|
| 40 mm | 320 dots | 472 dots |
| 50 mm | 400 dots | 590 dots |
| 100 mm | 800 dots | 1181 dots |
| 4 inch | 812 dots | 1200 dots |

Most label printers are 203 dpi. On a 300 dpi printer, the same text size
looks smaller, so use sizes about one and a half times larger.

| Option | Values |
|---|---|
| `labelHeight` | The length of one label, in dots. Leave it out for continuous paper; the label is then as long as the receipt. Anything longer than the label does not print. |
| `labelGap` | TSC only: the gap between two labels, in mm (default 2). |
| `darkness` | 0 to 30 for Zebra, 0 to 15 for TSC. Leave it out to keep the printer's own setting. |
| `dpi` | 203 (default), 300 or 600. TSC printers need it to work out the label size. |

```php
// 50 x 30 mm labels on a TSC printer
$renderer = new Renderer(options: new PrinterOptions(language: 'tspl', labelHeight: 240));
$rendered = $renderer->render(Receipt::make(400)->title('ڤیتامین C')->row('نرخ', '12,500'));
```

In the Laravel config, the same settings are called `language`,
`label_height`, `label_gap`, `darkness` and `dpi`. The `cut`, `feed` and
cash drawer settings are only for receipt printers.

### Testing your printer

Print a test page to check that everything works:

```bash
php artisan kurdish-escpos:test-print 192.168.1.50
php artisan kurdish-escpos:test-print /dev/usb/lp0 --width=384
php artisan kurdish-escpos:test-print 192.168.1.60 --language=zpl --width=812
```

The test page has the words «ڕاست» (right) and «چەپ» (left) at the two
edges of the paper. If one of them is cut off, your width is too large. If
nothing prints, try `--image=column`.

You can also give the name of a configured printer, such as
`test-print kitchen`, or test every printer at once with `--all`. Without an
address or a name, the command uses the default printer.

## Multiple printers

A shop often has more than one printer: one at the cashier, one in the
kitchen, one at the bar. Give each printer a name in
`config/kurdish-escpos.php`:

```php
'default' => 'cashier',

'printers' => [
    'cashier' => ['host' => '192.168.1.50'],
    'kitchen' => ['host' => '192.168.1.51', 'width' => 384],
    'bar' => ['path' => '/dev/usb/lp0', 'image' => 'column', 'cut' => 'none'],
    'labels' => ['host' => '192.168.1.60', 'language' => 'zpl', 'width' => 812],
],
```

Each printer can have its own `width`, `font`, `image`, `cut`, `feed`,
`drawer_pin`, `language` and label settings. The receipt is laid out again for each printer, so the same
receipt fits both 80 mm and 58 mm paper.

Print on one printer:

```php
KurdishEscpos::print($receipt);             // the default printer
KurdishEscpos::print($receipt, 'kitchen');  // a named printer
```

Print on several printers at the same time:

```php
$result = KurdishEscpos::print($receipt, ['cashier', 'kitchen', 'bar']);
```

Network printers get the receipt at the same moment, so they all print
together instead of waiting for each other. If one printer is off, the others
still print. Check the result to see what happened:

```php
if (! $result->ok()) {
    foreach ($result->failures() as $printer => $error) {
        Log::warning("Printer {$printer} failed: {$error->getMessage()}");
    }
}

$result->throw(); // or stop with an error if any printer failed
```

When you print on a single printer, a problem stops with a
`PrinterException`, as before.

To print different receipts at the same time, for example the customer
receipt at the cashier and the order ticket in the kitchen, use
`printEach()`:

```php
KurdishEscpos::printEach([
    'cashier' => $receipt,
    'kitchen' => $kitchenTicket,
]);
```

`KurdishEscpos::receipt('kitchen')` starts a receipt with the width of the
kitchen printer, and `php artisan kurdish-escpos:test-print --all` prints a
test page on every printer at once.

Without Laravel, pass the printers by name:

```php
use Rawand\KurdishEscpos\KurdishEscpos;
use Rawand\KurdishEscpos\Printers\NetworkPrinter;
use Rawand\KurdishEscpos\Printers\PrinterSetup;

$escpos = new KurdishEscpos(new Renderer, [
    'cashier' => new NetworkPrinter('192.168.1.50'),
    'kitchen' => new PrinterSetup(new NetworkPrinter('192.168.1.51'), width: 384),
], default: 'cashier');

$escpos->print($receipt, ['cashier', 'kitchen']);
```

## Fonts

The package comes with the [Vazirmatn](https://github.com/rastikerdar/vazirmatn)
font. It has all Kurdish, Arabic and English letters, including the Kurmanji
letters ê, î, û, ç and ş.

### Using another font

You can use any Kurdish `.ttf` font, such as NRT or Rudaw:

```php
$renderer = new Renderer('/path/to/NRT-Reg.ttf');
```

In Laravel, set the path in `.env`:

```dotenv
KURDISH_ESCPOS_FONT=/var/www/fonts/NRT-Reg.ttf
```

If your font is missing some letters (many Kurdish fonts have no English
letters), those letters are drawn with Vazirmatn, so nothing is left blank.

### Checking a font

To check if a font works, run the command below. It tells you if any
Kurdish letters are missing, and it saves a sample receipt so you can see
how the font looks:

```bash
php artisan kurdish-escpos:check-font /path/to/font.ttf --preview=sample.png

# without Laravel
vendor/bin/kurdish-escpos-check-font /path/to/font.ttf sample.png
```

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md)
for the development setup and guidelines, and run the checks before opening
a pull request:

```bash
composer test
composer format
composer analyse
```

To report a security vulnerability, follow [SECURITY.md](SECURITY.md) instead
of opening a public issue. All changes are listed in
[CHANGELOG.md](CHANGELOG.md).

## Credits

- [RawanD201](https://github.com/RawanD201)

## License

The package is released under the [MIT License](LICENSE). The bundled
Vazirmatn font is licensed under the
[SIL Open Font License 1.1](resources/fonts/OFL-Vazirmatn.txt).
