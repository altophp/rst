# Sphinx

Use the Sphinx profile for documentation that builds on Docutils with Sphinx
directives, roles, and cross-document conventions.

```php
use Alto\Rst\Rst;

$rst = Rst::sphinx();
$result = $rst->parse($source);
$html = $rst->toHtml($source);
```

This profile includes the Docutils capabilities and adds syntax implemented for
Sphinx documentation, including `toctree`, version notices, literal includes,
highlighting, glossary and index constructs, cross-references, and Python domain
roles.

Choose it for Sphinx-oriented projects that do not require Symfony's additional
directives and PHP roles. ALTO RST supports the documented subset implemented by
the package; it does not run Sphinx or claim compatibility with the complete
Sphinx toolchain.
