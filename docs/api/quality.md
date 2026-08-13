# Quality API

Quality operations are independent: the linter reports, the fixer applies a
small set of safe corrections, and the formatter normalizes selected layout.

## Linter

`Linter::lint(Document, LintConfig, ?Source, ?ReferenceGraph, ?Profile)` returns
a `ProblemReport`. `LintConfig::recommended()` creates the built-in policy;
`withRule()` and `withoutRule()` derive a new value.

## FixEngine

`FixEngine::fix(string $bytes, ?FixOptions, ?Profile)` returns `FixResult` with
changed bytes, patches, and reparsed state. `FixOptions` controls trailing
whitespace, blank-line bounds, anchor and directive separators, and optional
default-role normalization.

## Formatter

`Formatter::format(string $bytes, ?FormatOptions, ?Profile)` returns
`FormatResult`. Formatting candidates pass a semantic reparse guard before
their patches are accepted.

See [Linting](../linting/index.md), [Fixes](../linting/fixes.md), and
[Formatting](../editing/formatting.md) for complete workflows.
