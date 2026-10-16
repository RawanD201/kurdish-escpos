<p align="center">
  <img src="art/banner.png" alt="kurdish-escpos: print correctly shaped Kurdish, Arabic and English receipts on ESC/POS printers with pure PHP" width="100%">
</p>

<p align="center">
  <a href="https://packagist.org/packages/rawand201/kurdish-escpos"><img src="https://img.shields.io/packagist/v/rawand201/kurdish-escpos?label=packagist" alt="Latest version"></a>
  <a href="https://github.com/RawanD201/kurdish-escpos/actions/workflows/tests.yml"><img src="https://github.com/RawanD201/kurdish-escpos/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="https://packagist.org/packages/rawand201/kurdish-escpos"><img src="https://img.shields.io/packagist/dependency-v/rawand201/kurdish-escpos/php" alt="PHP version"></a>
  <a href="https://packagist.org/packages/rawand201/kurdish-escpos"><img src="https://img.shields.io/packagist/dt/rawand201/kurdish-escpos" alt="Total downloads"></a>
  <a href="LICENSE"><img src="https://img.shields.io/packagist/l/rawand201/kurdish-escpos" alt="License"></a>
</p>

# kurdish-escpos

Print Kurdish (Sorani and Badini), Arabic and English receipts on ESC/POS
thermal printers from PHP, with every letter joined and every line in the
right order. The package is written in pure PHP and needs only the GD
extension, so it runs on ordinary shared hosting as well as on servers.

<div dir="rtl">

**kurdish-escpos** پاکێجێکی PHPیە بۆ چاپکردنی وەسڵ بە کوردی (سۆرانی و بادینی)، عەرەبی و ئینگلیزی لەسەر پرینتەری ESC/POS. پیتەکان بە دروستی پێکەوە دەلکێن و دەق بە ڕێکی لە ڕاستەوە بۆ چەپ دەنووسرێت. هیچ بەرنامەیەکی دەرەکی پێویست نییە؛ تەنها PHP و GD.

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
- [Installation](#installation)
- [Usage with Laravel](#usage-with-laravel)
- [Usage without Laravel](#usage-without-laravel)
- [Building receipts](#building-receipts)
- [Printers](#printers)
- [Fonts](#fonts)
- [How it works](#how-it-works)
- [Frequently asked questions](#frequently-asked-questions)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)

## Why this package exists

Printing Kurdish on a receipt printer is harder than it looks:

- Receipt printers have built-in character sets for Latin, Cyrillic and some
  Arabic, but none contain the Kurdish letters ڕ, ڵ, ێ and ە.
- Printers do not join Arabic-script letters and do not handle right-to-left
  text.
- PHP's GD extension draws each character on its own (PHP bug
  [#43899](https://bugs.php.net/bug.php?id=43899)), so drawing Kurdish text
  into an image produces separate, unjoined letters.
- Existing PHP "reshaper" libraries replace letters with Unicode
  presentation forms. ڕ, ڵ, ێ and ە have no presentation forms, so these
  libraries cannot join them.

This package solves the problem the way browsers and operating systems do. It
reads the joining rules from the font itself, shapes the text, draws it into
an image, and sends the image to the printer. The printer needs no Kurdish
support at all.

## Features

- Correct joining of every Kurdish letter, including ڵ and ێ in the middle
  of a word, and Arabic ligatures such as لا and الله.
- Mixed right-to-left and left-to-right text, such as prices, dates and
  English product names inside Kurdish lines.
- Output identical to HarfBuzz, the shaping engine used by Chrome, Firefox
  and Android, on every font tested.
- Support for your own fonts, including popular Kurdish fonts such as NRT,
  Rudaw, Kurdistan 24 and Speda.
- Text, titles, item rows, dividers, QR codes and paper feed, for 80 mm and
  58 mm paper.
- A PNG preview that matches the printed dots exactly.
- Network printers, USB printers on Linux, and shared printers on Windows.
- Laravel service provider, facade and configuration, with auto-discovery.
- No binaries, no Node.js, no headless browser: only PHP and GD.

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

### Filament

A "Print receipt" action in a Filament resource:

```php
use Filament\Actions\Action; // Filament\Tables\Actions\Action in Filament 3

Action::make('printReceipt')
    ->label('Print receipt')
    ->icon('heroicon-o-printer')
    ->action(fn (Order $record) => PrintReceipt::dispatch($record));
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
(new NetworkPrinter('192.168.1.50'))->send($rendered->escpos);
```

## Building receipts

| Method | Description |
|---|---|
| `text($text, $size = null, $align = 'start')` | A paragraph. Long lines wrap, and `\n` starts a new paragraph. `start` is the right edge for Kurdish and Arabic and the left edge for English; `center` and `end` are also available. |
| `title($text, $size = 40)` | Large, centered text, such as the shop name. |
| `row($name, $price, $size = null)` | An item name on the right and a price on the left. Long names wrap. |
| `divider()` | A dashed horizontal rule. |
| `qr($data, $size = null)` | A centered QR code, about 240 dots wide by default. |
| `feed($lines = 1)` | Vertical space. |

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
    { "type": "row", "right": "کوڵێرە", "left": "1,250" },
    { "type": "divider" },
    { "type": "qr", "data": "https://example.com/r/1042" },
    { "type": "feed", "lines": 1 }
  ]
}
```

Unknown fields and invalid values are reported with a clear error message
rather than ignored.

## Printers

| Printer | Class | Example |
|---|---|---|
| Ethernet or Wi-Fi | `NetworkPrinter` | `new NetworkPrinter('192.168.1.50')` |
| USB on Linux | `FilePrinter` | `new FilePrinter('/dev/usb/lp0')` |
| Shared printer on Windows | `FilePrinter` | `new FilePrinter('\\\\localhost\\XPrinter')` |

Network printers almost always listen on port 9100; a different port can be
passed as the second argument. A `FilePrinter` works only when the printer is
connected to the machine that runs PHP, and the PHP process needs permission
to write to it (on Linux, usually membership of the `lp` group).

The output uses the standard ESC/POS raster command (`GS v 0`), which is
supported by Epson printers and by the many printers that are compatible
with them, such as most Xprinter models.

## Fonts

The package includes [Vazirmatn](https://github.com/rastikerdar/vazirmatn),
which covers Kurdish, Arabic, Persian and Latin, including the Kurmanji
letters ê, î, û, ç and ş.

### Using another font

Any Unicode TrueType font (`.ttf`) with OpenType joining rules can be used:

```php
$renderer = new Renderer('/path/to/NRT-Reg.ttf');
```

In Laravel, set the path in `.env`:

```dotenv
KURDISH_ESCPOS_FONT=/var/www/fonts/NRT-Reg.ttf
```

Characters that are missing from your font, such as English letters in many
Kurdish fonts, are drawn with Vazirmatn instead of being left blank.

### Checking a font

Before using a font, check that it contains all Kurdish letters and joining
rules. The check also saves a sample receipt, so you can see the result:

```bash
php artisan kurdish-escpos:check-font /path/to/font.ttf --preview=sample.png

# without Laravel
vendor/bin/kurdish-escpos-check-font /path/to/font.ttf sample.png
```

### Tested fonts

The following fonts were tested against HarfBuzz, and the package produced
identical output for every test sentence:

| Fonts | Source |
|---|---|
| Vazirmatn (bundled) | [Google Fonts](https://fonts.google.com/specimen/Vazirmatn) |
| NRT, Rudaw, Kurdistan 24 Bold and Light, Speda, Unikurd Jino, UniMahan Nurhan, Sarchia Makka, Nizar Aldhabi, Nizar Nastaliq Kurdish | [kurdfonts.com](https://www.kurdfonts.com/) (not included; check each font's license) |
| Noto Naskh Arabic, Noto Sans Arabic, Noto Kufi Arabic, Noto Nastaliq Urdu, Amiri, Scheherazade New, Harmattan, Lateef, IBM Plex Sans Arabic | [Google Fonts](https://fonts.google.com/) |

Fonts made only for Arabic, such as Cairo or Tajawal, lack the Kurdish
letters; the font check reports this.

<p align="center">
  <img src="art/fonts.png" alt="The same receipt printed with six different fonts" width="100%">
</p>

### Limitations

- Fonts with PostScript outlines (most `.otf` files) are not supported yet.
  The font check reports such fonts.
- Variable fonts are drawn at their default weight.
- Old non-Unicode Kurdish fonts, such as the Ali-K family, store Kurdish
  letters under the codes of other characters and cannot work with any
  modern software.
- Fonts differ in design size. Some, such as Kurdistan 24, appear smaller at
  the same size and may need a larger `size`.

## How it works

1. **Shaping.** The text is converted into glyphs using the font's OpenType
   tables (GSUB and GPOS): the correct form of each letter at the start,
   middle or end of a word, ligatures, kerning and the placement of vowel
   marks.
2. **Bidirectional layout.** The Unicode Bidirectional Algorithm orders
   right-to-left and left-to-right text on each line.
3. **Drawing.** The glyph outlines are filled with GD at four times the
   final resolution and scaled down for smooth edges.
4. **Printing.** The image is converted to black and white dots and sent with
   the ESC/POS raster command, followed by a paper feed and a cut.

A typical receipt renders in about 130 ms and uses less than 10 MB of memory.

## Frequently asked questions

**Does it support Badini?**
Yes. Badini uses the same Arabic-script letters as Sorani, and Latin-script
Kurmanji is also supported.

**Can a website print to a USB printer at the cashier's desk?**
Only if PHP runs on the computer the printer is connected to. For an online
application, use a network printer, or connect the USB printer to a computer
on the same network and share it.

**Why not convert the text to the printer's character set?**
No printer character set contains the Kurdish letters, and none can join
Arabic-script letters. Printing an image avoids both problems and works on
any ESC/POS printer.

**Has it been tested on real printers?**
The output follows the ESC/POS specification and is verified byte for byte
in the test suite. Reports from real printers are very welcome; please open
an issue with your printer model, whether or not everything worked.

## Roadmap

- A Filament plugin with a print action and preview
- Logos and other images on receipts
- Barcodes
- Bold text through a second font file
- Fonts with PostScript (CFF) outlines

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

- [RawanD201](https://github.com/RawanD201), author and maintainer
- [Saber Rastikerdar](https://github.com/rastikerdar) and contributors, for
  the Vazirmatn font
- The [HarfBuzz](https://harfbuzz.github.io/) project, whose behavior the
  shaper follows and is tested against
- [Bacon QR Code](https://github.com/Bacon/BaconQrCode), used to generate QR
  codes
- [All contributors](https://github.com/RawanD201/kurdish-escpos/contributors)

## License

The package is released under the [MIT License](LICENSE). The bundled
Vazirmatn font is licensed under the
[SIL Open Font License 1.1](resources/fonts/OFL-Vazirmatn.txt).
