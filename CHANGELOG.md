# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.1] - 2026-10-16

### Changed

- No code changes. This release is the same code as 0.5.0, published again
  from the cleaned-up git history. Packagist keeps published versions on
  their original commits, so 0.1.0 to 0.5.0 are no longer installable;
  use `^0.5` or later.

## [0.5.0] - 2026-10-16

### Changed

- The PHP namespace is now `RawanD201\KurdishEscpos`, the same as the
  author name. Replace `Rawand\KurdishEscpos` with `RawanD201\KurdishEscpos`
  in your `use` statements. Nothing else changes.

## [0.4.0] - 2026-10-16

### Added

- Label printers: Zebra (ZPL) and TSC (TSPL). Set the printer `language` to
  `zpl` or `tspl`; the default is still `escpos`. The ZPL output was checked
  against the Labelary ZPL viewer, which drew the sample receipt dot for dot.
- Label options: `labelHeight` (label length in dots, or continuous paper),
  `labelGap` (TSC), `darkness` and `dpi`. In Laravel: `language`,
  `label_height`, `label_gap`, `darkness`, `dpi` and the
  `KURDISH_ESCPOS_LANGUAGE` environment variable.
- `RenderedReceipt::commands()` returns the printer commands in any
  language. The `escpos` property still works and holds the same bytes.
- `kurdish-escpos:test-print --language=zpl|tspl`.
- `Bitmap::toZpl()`, `Bitmap::toTspl()` and `Bitmap::toCommands()`.

### Changed

- The maximum width is 2560 dots instead of 2048, for 600 dpi label
  printers.
- Empty values in the printer configuration, such as an empty `.env`
  variable, now fall back to the defaults instead of causing an error.

## [0.3.0] - 2026-10-16

### Added

- Several named printers, each with its own width, font and settings: the
  `printers` list in the configuration, or `PrinterSetup` without Laravel.
- Printing on several printers at the same time with
  `print($receipt, ['cashier', 'kitchen'])`. Network printers receive the
  receipt in parallel, and a printer that fails does not stop the others.
- `printEach()` prints different receipts on different printers at once.
- `PrintResult` reports which printers printed and which failed.
- `kurdish-escpos:test-print` accepts a printer name and `--all`.

### Changed

- `print()` returns a `PrintResult` instead of a `RenderedReceipt`. Use
  `$result->rendered()` to get the PNG preview and the printer bytes.
- The configuration has `default` and `printers` instead of a single
  `printer` entry. Configuration files published with the old `printer`
  entry keep working.

## [0.2.0] - 2026-10-16

### Added

- Support for older printers with the `ESC *` picture command
  (`PrinterOptions(image: 'column')`).
- Cut, paper feed and cash drawer settings in `PrinterOptions`, and
  `Receipt::openDrawer()`.
- `php artisan kurdish-escpos:test-print` prints a test page that checks the
  printer and the paper width.
- A "Supported printers" section in the README.

## [0.1.0] - 2026-10-16

First release.

### Added

- Receipt builder with text, titles, item rows, dividers, QR codes and feeds,
  for 80 mm and 58 mm paper. Item rows follow the direction of the item name.
- Pure PHP text shaping based on the font's OpenType rules, matching HarfBuzz
  output for Kurdish (Sorani and Badini), Arabic and Latin text.
- Unicode Bidirectional Algorithm for mixed right-to-left and left-to-right
  text.
- Support for Sorani, Badini, Hawrami and the other Kurdish dialects, in Arabic
  and Latin script.
- Rendering with GD to a PNG preview and an ESC/POS raster command stream.
- Network (TCP) and file-based (USB device or Windows shared) printers.
- Support for custom TrueType fonts, with automatic fallback to the bundled
  Vazirmatn font for missing characters.
- Font compatibility check, available as an Artisan command and as a
  standalone script.
- Laravel service provider, facade and configuration file.

[Unreleased]: https://github.com/RawanD201/kurdish-escpos/compare/v0.5.1...HEAD
[0.5.1]: https://github.com/RawanD201/kurdish-escpos/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/RawanD201/kurdish-escpos/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/RawanD201/kurdish-escpos/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/RawanD201/kurdish-escpos/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/RawanD201/kurdish-escpos/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/RawanD201/kurdish-escpos/releases/tag/v0.1.0
