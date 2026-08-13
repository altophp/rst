# Conversion

Alto converts RST to Markdown and a supported Markdown subset back to RST.
Every conversion returns output plus a report of unsupported, lossy, or
approximate mappings.

| Input | API |
| --- | --- |
| Parsed RST document | `RstToMarkdown` |
| Supported Markdown | `MarkdownReader` then `MarkdownToRst` |
| RST documentation tree | `ProjectConverter` |

Conversion does not hide fidelity gaps. A source construct without an exact
target degrades conservatively and becomes a `ConversionIssue`.

Start with [To Markdown](to-markdown.md) or [To RST](to-rst.md). Use
[Projects](projects.md) when links cross file boundaries, and always inspect
[Reports](reports.md) before publishing migrated content.
