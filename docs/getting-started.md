# Getting started

Render a Sphinx-style document, inspect its title node, and keep parser and
reference problems visible.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Rst\Rst;

$source = <<<'RST'
Guide
=====

Read the `project site <https://example.com>`_.
RST;

$rst = Rst::sphinx();
$result = $rst->parse($source);

echo $rst->toHtml($source);
echo count($result->problems())."\n";
echo count($result->references()->problems())."\n";
```

The two counts are `0`. The HTML contains a section heading and one safe link.

`sphinx()` recognizes standard RST plus Sphinx roles and directives.
`ParseResult` keeps the document, source positions, parser recovery problems,
and a lazily built reference graph.

Continue with [Parsing](parsing/index.md) to inspect the object model or
[Rendering](rendering/index.md) when HTML is the only result.
