# Conversion

ALTO RST converts RST to Markdown and a supported Markdown subset back to RST.
Every conversion returns output plus a report of unsupported, lossy, or
approximate mappings.

| Input | API |
| --- | --- |
| Parsed RST document | `RstToMarkdown` |
| Supported Markdown | `MarkdownReader` then `MarkdownToRst` |
| RST documentation tree | `ProjectConverter` |

Conversion does not hide fidelity gaps. A source construct without an exact
target degrades conservatively and becomes a `ConversionIssue`.

Continue with:

- [To Markdown](conversion/to-markdown.md) to convert RST safely.
- [To RST](conversion/to-rst.md) to convert supported Markdown.
- [Projects](conversion/projects.md) when links cross file boundaries.
- [Reports](conversion/reports.md) before publishing migrated content.

## Public contract

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
