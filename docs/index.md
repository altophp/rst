# ALTO RST

ALTO RST parses reStructuredText into a source-positioned object model and
renders safe HTML without running Python or Sphinx. The same model supports
linting, formatting, editing, references, and conversion.

```php
use Alto\Rst\Rst;

$html = Rst::sphinx()->toHtml("Guide\n=====\n\nWelcome.\n");
```

The package has no runtime Composer dependencies. It supports docutils RST,
Sphinx syntax, and Symfony documentation conventions without claiming to
replace the complete Sphinx toolchain.

## Documentation

- [Installation](installation.md)
- [Getting started](getting-started.md)
- [Parsing](parsing.md)
- [Rendering](rendering.md)
- [Linting](linting.md)
- [Editing](editing.md)
- [Conversion](conversion.md)
- [References](references.md)
- [Extensions](extensions.md)
- [Security](security.md)
- [Errors](errors.md)

HTML output escapes text, filters unsafe URLs, and keeps file-reading
constructs disabled by default. Read [Security](security.md) before granting
additional authority.

The public contract consists of documented types and methods. Types marked
`@internal` in source remain implementation details.
