# Linting

The linter applies structural, source, and reference rules to an existing parse
result. It reports problems and never changes input.

```php
use Alto\Rst\Lint\Linter;
use Alto\Rst\Lint\LintConfig;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;

$source = Source::fromString($input);
$parsed = Rst::symfony()->parse($source->bytes);

$report = new Linter()->lint(
    $parsed->document(),
    LintConfig::recommended(),
    $source,
    $parsed->references(),
    Rst::symfony()->profile(),
);
```

Each `Problem` contains a severity, stable code, message, and optional source
span. Parser problems and lint findings remain separate reports.

Document rules need only the tree. Source rules also need original bytes.
Context rules use the reference graph. Omitting optional inputs means rules
that require those inputs cannot run.

Continue with:

- [Configuration](linting/configuration.md) to derive a policy.
- [Fixes](linting/fixes.md) for conservative corrections.
- [Rules](linting/rules.md) to browse the built-in set.
- [Custom rules](linting/custom.md) to add an application rule.

## Public contract

`Linter::lint(Document, LintConfig, ?Source, ?ReferenceGraph, ?Profile)` returns
a `ProblemReport`. `LintConfig::recommended()` creates the built-in policy;
`withRule()` and `withoutRule()` derive a new value.

`FixEngine::fix(string $bytes, ?FixOptions, ?Profile)` returns a `FixResult`
with changed bytes, patches, and reparsed state. `FixOptions` controls trailing
whitespace, blank-line bounds, anchor and directive separators, and optional
default-role normalization.
