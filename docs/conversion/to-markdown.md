# To Markdown

Parse RST with the intended profile, then pass the document, exact source, and
reference graph to `RstToMarkdown`. With Composer's autoloader loaded:

```php
use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\RstToMarkdown;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;

$input = "Guide\n=====\n\nWelcome.\n";
$source = Source::fromString($input);
$rst = Rst::symfony();
$parsed = $rst->parse($source->bytes);

$result = new RstToMarkdown()->convert(
    $parsed->document(),
    $source,
    $rst->profile(),
    ConversionOptions::symfony(),
    $parsed->references(),
);

echo $result->output;
```

This example prints:

```markdown
# Guide

Welcome.
```

Headings, prose, inline markup, links, lists, tables, code, admonitions,
footnotes, citations, substitutions, and supported Sphinx or Symfony
constructs receive explicit mappings.

File-reading directives stay inert unless `ConversionOptions` receives an
explicit `FileAccessPolicy`. Raw HTML requires separate authority. Read
[Security](../security.md) before enabling either option and [Reports](reports.md)
before accepting the output.
