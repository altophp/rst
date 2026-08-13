# To RST

`MarkdownReader` reads the subset Alto writes plus constructs required by its
documentation workload. It is not a general CommonMark implementation.

```php
use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\Markdown\MarkdownReader;
use Alto\Rst\Convert\MarkdownToRst;

$document = new MarkdownReader()->read($input);
$result = new MarkdownToRst()->convert(
    $document,
    ConversionOptions::symfony(),
);

echo $result->output;
```

`ConversionOptions` controls section adornments, list markers, indentation,
code-block directive names, fence and link styles, and other shared choices.
Use `ConversionOptions::symfony()` for Symfony documentation conventions.

Unsupported Markdown degrades conservatively and appears in the report. Check
the result as described in [Reports](reports.md) before replacing source files.
