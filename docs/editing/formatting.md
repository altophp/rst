# Formatting

`Formatter` normalizes selected layout while preserving document semantics. It
returns changed bytes and patches without writing a file.

```php
use Alto\Rst\Format\FormatOptions;
use Alto\Rst\Format\Formatter;
use Alto\Rst\Profile\Profile;

$result = new Formatter()->format(
    $input,
    new FormatOptions(
        normalizeSectionAdornments: true,
        bulletMarker: '-',
        alignSimpleTables: true,
        lineWidth: 80,
    ),
    Profile::symfony(),
);
```

The formatter can normalize section adornments and bullet markers, align
simple tables, and wrap eligible plain prose. It skips ambiguous Unicode title
widths, structural list boundaries, complex tables, directives, literals,
markup-heavy prose, and any candidate that changes the reparsed tree.

Formatting is idempotent:

```php
$first = new Formatter()->format($input);
$second = new Formatter()->format($first->bytes);

assert([] === $second->patches);
```

Extension passes use the same parse guard. See [Patches](patches.md) to preview
the accepted changes.
