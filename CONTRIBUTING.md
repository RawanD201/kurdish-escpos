# Contributing

Contributions are welcome, whether they are bug reports, fixes, new
features, documentation or test cases from real receipts and printers.

## Reporting problems

Before opening an issue, please check whether it has already been reported.
For text that prints incorrectly, include the receipt code or JSON, the font,
and the PNG preview. A photo of the printed receipt is also helpful when the
preview looks correct but the paper does not.

Security vulnerabilities should not be reported in public issues. See
[SECURITY.md](SECURITY.md).

## Development setup

The package requires PHP 8.2 or later with the `gd` and `iconv` extensions.

```bash
git clone https://github.com/RawanD201/kurdish-escpos.git
cd kurdish-escpos
composer install
```

The following commands must pass before a pull request is merged:

```bash
composer test      # PHPUnit
composer format    # Laravel Pint
composer analyse   # PHPStan
```

To see the effect of a change on a real receipt, render the example and open
`examples/receipt.png`:

```bash
php examples/render.php
```

## Pull requests

- Create a branch from `main` and keep each pull request focused on one change.
- Add or update tests for every change in behavior.
- Add an entry to the "Unreleased" section of [CHANGELOG.md](CHANGELOG.md).
- Update the README when the public API changes.

## Text shaping

The shaper in `src/Text` must produce the same glyphs and positions as
HarfBuzz, the shaping engine used by browsers and operating systems.
`tests/ShaperTest.php` compares the output with reference data in
`tests/fixtures/harfbuzz*.json`, which was recorded with HarfBuzz 14.6.0
through its Python binding (uharfbuzz). Each glyph is stored as
`[id, cluster, x_advance, x_offset, y_offset]`, in font units, for the
direction, script and language given in the fixture.

A change to the shaper must keep these tests passing. If you add a feature
that a font requires, please include reference data recorded with HarfBuzz
that demonstrates it.

The bidi reference data in `tests/fixtures/bidi.json` was recorded with
python-bidi, which does not mirror brackets; the test therefore compares
character order without mirroring.

## Fonts

Only fonts released under a license that permits redistribution, such as the
SIL Open Font License, may be added to the repository. Fonts found on
download sites usually do not state such a license and must not be
committed, including in test fixtures.

## Code of conduct

This project follows the [Code of Conduct](CODE_OF_CONDUCT.md). By
participating, you are expected to uphold it.
