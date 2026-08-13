# API reference

The public API is organized by task. Types marked `@internal` in source are
implementation details and are not part of the package contract.

## Entry points

`Rst::docutils()`, `Rst::sphinx()`, and `Rst::symfony()` create immutable
engines with a selected profile. Each engine exposes `profile()`,
`profileName()`, `parse()`, and `toHtml()`.

```php
use Alto\Rst\Rst;

$rst = Rst::sphinx();
$parsed = $rst->parse($source);
```

## Reference domains

- [Parsing](parsing.md): engines, profiles, sources, nodes, and parse results.
- [Rendering](rendering.md): HTML renderer, options, and URL policy.
- [Quality](quality.md): linting, safe fixes, and formatting.
- [Editing](editing.md): typed editor, patches, diffs, and files.
- [Conversion](conversion.md): document and project converters.
- [References](references.md): local and cross-document resolution.
- [Extensions](extensions.md): profile contribution contracts.
- [Exceptions](exceptions.md): invalid arguments, patches, and files.

Use guide pages for workflows and decisions. These pages define the callable
surface and observable boundaries.
