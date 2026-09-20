# Symfony

Use the Symfony profile for Symfony and Symfony UX documentation conventions.

```php
use Alto\Rst\Rst;

$rst = Rst::symfony();
$result = $rst->parse($source);
$html = $rst->toHtml($source);
```

This profile includes the Sphinx and Docutils capabilities, then installs the
Symfony extension. It adds Symfony documentation directives such as
`configuration-block` and PHP-oriented roles used across Symfony documentation.
Its `class` role follows Symfony's PHP convention instead of the Sphinx
`py:class` alias.

Choose it when parsing, linting, formatting, or converting Symfony documentation.
Use [Extensions](../../extensions.md) when an application needs additional
trusted behavior.
