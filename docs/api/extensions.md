# Extensions API

`Extension` is a named bundle of optional profile contributions.

| Method | Return values |
| --- | --- |
| `directives()` | `DirectiveSpec` values |
| `roles()` | `RoleSpec` values |
| `directiveHandlers()` | `DirectiveHandler` values |
| `roleHandlers()` | `RoleHandler` values |
| `lintRules()` | context, document, or source rules |
| `fixPasses()` | `FixPass` values |
| `formatterPasses()` | `FormatterPass` values |
| `statisticsProviders()` | `StatisticsProvider` values |

`AbstractExtension` supplies empty implementations so an extension overrides
only the contracts it owns. `Profile::withExtension()` returns a new profile.

Handler contexts preserve the active renderer or converter state. Source
passes return patches; statistics return namespaced scalar values. Extensions
cannot activate raw HTML or file reads without the corresponding application
policy.

See [Extension points](../extensions/extension-points.md) and
[Custom extension](../extensions/custom.md).
