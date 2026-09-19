# Extensions

Extensions add trusted directives, roles, handlers, lint rules, fixes,
formatting passes, statistics, or conversion mappings to an immutable profile.

## Symfony extension

`Profile::symfony()` installs `SymfonyExtension`. It adds the
`configuration-block`, `best-practice`, and `screencast` directives plus
Symfony PHP symbol roles.

```php
use Alto\Rst\Profile\Profile;

$profile = Profile::symfony();
```

The configuration-block and screencast handlers render and convert structured
bodies through the active parent context. PHP symbol roles render as code and
preserve visible titles during conversion.

## Trust boundary

Extensions execute PHP code and are trusted. Handlers must escape HTML through
the received policy, source passes must return valid patches, and resource
access remains controlled by explicit application policies.

Continue with:

- [Extension points](extensions/extension-points.md) to choose a contract.
- [Custom extension](extensions/custom.md) for a complete small implementation.

## Public contract

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
