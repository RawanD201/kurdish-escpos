# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/RawanD201/kurdish-escpos/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/RawanD201/kurdish-escpos/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/RawanD201/kurdish-escpos/releases/tag/v0.1.0
