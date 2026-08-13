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

Read [Configuration](configuration.md) to derive a policy, [Rules](rules.md)
to browse the built-in set, and [Fixes](fixes.md) for safe corrections.
