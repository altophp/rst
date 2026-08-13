# Configuration

`LintConfig` is an immutable collection keyed by each rule's stable code.

```php
use Alto\Rst\Lint\LintConfig;
use Alto\Rst\Lint\Rule\IndentationRule;
use Alto\Rst\Lint\Rule\MaxLineLengthRule;

$config = LintConfig::recommended()
    ->withoutRule('lint/american-english')
    ->withRule(new IndentationRule(2))
    ->withRule(new MaxLineLengthRule(100));
```

`withRule()` adds or replaces a rule with the same code. `withoutRule()`
ignores an unknown code. This makes package and application policies easy to
derive without mutable global configuration.

Pass the active profile to `Linter::lint()` when profile extensions contribute
rules. An extension rule replaces a configured rule with the same code, making
the effective set deterministic.

See [Rules](rules.md) for built-in codes and [Custom rules](custom.md) for the
three rule contracts.
