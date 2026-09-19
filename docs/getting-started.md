# Getting started

Render a Sphinx-style document and keep parser and reference problems visible.
After [installation](installation.md), save this as `render.php` beside
`vendor/` and run `php render.php`.

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

The complete output is:

```text
<section id="guide">
<h1>Guide</h1>
<p>Read the <a href="https://example.com">project site</a>.</p>
</section>
0
0
```

The first count reports parser problems; the second reports reference problems.
A document can parse successfully while still containing unresolved references.
This script uses direct rendering for simplicity; [Rendering](rendering.md)
shows how to render an existing parse result without parsing again.

`sphinx()` recognizes standard RST plus Sphinx roles and directives.
`ParseResult` keeps the document, source positions, parser recovery problems,
and a lazily built reference graph.

Continue with [Parsing](parsing.md) to inspect the object model or
[Rendering](rendering.md) when HTML is the only result.
