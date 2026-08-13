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

Read [Extension points](extension-points.md) to choose a contract and
[Custom extension](custom.md) for a complete small implementation.
