# Docutils

Use the Docutils profile for portable reStructuredText without Sphinx or
Symfony-specific syntax.

```php
use Alto\Rst\Rst;

$rst = Rst::docutils();
$result = $rst->parse($source);
$html = $rst->toHtml($source);
```

The profile provides the standard directives and roles implemented by ALTO RST,
including admonitions, images, figures, code blocks, tables, contents, includes,
substitutions, references, and common inline roles. It does not enable Sphinx
cross-reference roles, `toctree`, or Symfony documentation extensions.

Choose this profile when documents should remain usable by ordinary Docutils
tooling. File-reading directives remain subject to the policies described in
[Security](../../security.md).
