# CHANGELOG

## [0.9.0] - 2026-08-07

First public release. The API is complete but not frozen: it may still change
before 1.0.

### Added
- `Rst` entry point with the `docutils()`, `sphinx()`, and `symfony()` profiles.
- Direct rendering: `toHtml()`.
- `parse()` returning the ROM, a source-positioned object model, together with
  typed parser problems and a reference report.
- Reference graph resolving local and project-wide targets, links, footnotes,
  citations, and substitutions.
- Linter with configurable recommended rules, plus `FixEngine`, `Formatter`,
  and `Editor` for conservative fixes, formatting, and typed edits.
- Patches, diffs, and conflict-safe file persistence: operations return exact
  patches before anything is written.
- Conversion in both directions, `RstToMarkdown` and `MarkdownToRst`, plus
  `ProjectConverter::convertDirectory()` for whole documentation trees, with
  every unsupported, lossy, or approximate mapping aggregated for review.
- Extension contracts for directives, roles, lint rules, fixes, formatter
  passes, statistics, and conversion mappings, through compiled profiles.

### Notes
- Requires PHP 8.4 or newer. The core has no runtime Composer dependencies.
- Output is safe by default: text escaped, unsafe URL schemes filtered, and
  file-reading directives disabled.
- Malformed input recovers into typed problems instead of losing source text.
- Verified against a pinned docutils 0.23 fixture corpus. docutils is a
  development oracle, not a runtime dependency: no Python or Sphinx process is
  involved at runtime.

[0.9.0]: https://github.com/altophp/rst/releases/tag/v0.9.0
