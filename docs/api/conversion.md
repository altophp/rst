# Conversion API

Conversion APIs return `ConversionResult`, which contains `output`, a
`ConversionReport`, resolved-reference spans, and status helpers.

| API | Input | Result |
| --- | --- | --- |
| `RstToMarkdown::convert()` | document, source, profile, options, reference context | Markdown result |
| `MarkdownReader::read()` | Markdown bytes | supported Markdown model |
| `MarkdownToRst::convert()` | Markdown model and options | RST result |
| `ProjectConverter::convertDirectory()` | RST root directory | project result |
| `ProjectConverter::convertSources()` | path-to-source map | project result |

`ConversionOptions` controls output styles plus explicit file and raw HTML
authority. `ConversionOptions::symfony()` supplies Symfony documentation
defaults.

`ConversionReport` provides status, exactness helpers, issue filters, and
counts grouped by kind or construct. Project results add file lookup, output
maps, and parser and reference diagnostics.

See [Conversion](../conversion/index.md), [Projects](../conversion/projects.md),
and [Reports](../conversion/reports.md).
