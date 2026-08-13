# Extension points

Extend `AbstractExtension` and override only the contracts owned by the
extension.

| Method | Contribution | Execution point |
| --- | --- | --- |
| `directives()` | `DirectiveSpec` | Parsing and profile checks |
| `roles()` | `RoleSpec` | Inline parsing and profile checks |
| `directiveHandlers()` | `DirectiveHandler` | HTML and Markdown conversion |
| `roleHandlers()` | `RoleHandler` | HTML and Markdown conversion |
| `lintRules()` | lint rule | Linting with the active profile |
| `fixPasses()` | `FixPass` | Safe fixing |
| `formatterPasses()` | `FormatterPass` | Formatting under the parse guard |
| `statisticsProviders()` | `StatisticsProvider` | Namespaced document metrics |

```php
$extended = $profile->withExtension($extension);
```

The returned profile is new. Later extensions replace handlers, passes, rules,
or providers with the same normalized name.

Directive body kinds declare whether content is parsed as blocks, preserved as
literal data, retained as opaque syntax, or forbidden. Choose the exact model
so references are neither invented inside code nor hidden inside ordinary RST.

See [Custom extension](custom.md) for registration and [Security](../security.md)
for authority boundaries.
