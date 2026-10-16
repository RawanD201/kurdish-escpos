# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Receipt builder with text, titles, item rows, dividers, QR codes and feeds,
  for 80 mm and 58 mm paper.
- Pure PHP text shaping based on the font's OpenType rules, matching HarfBuzz
  output for Kurdish (Sorani and Badini), Arabic and Latin text.
- Unicode Bidirectional Algorithm for mixed right-to-left and left-to-right
  text.
- Rendering with GD to a PNG preview and an ESC/POS raster command stream.
- Network (TCP) and file-based (USB device or Windows shared) printers.
- Support for custom TrueType fonts, with automatic fallback to the bundled
  Vazirmatn font for missing characters.
- Font compatibility check, available as an Artisan command and as a
  standalone script.
- Laravel service provider, facade and configuration file.
