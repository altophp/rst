# Profiles

A profile defines the directives, roles, handlers, and extension behavior
available during parsing and later operations.

```php
use Alto\Rst\Rst;

$docutils = Rst::docutils();
$sphinx = Rst::sphinx();
$symfony = Rst::symfony();
```

| Profile | Use for |
| --- | --- |
| `docutils` | Portable reStructuredText. |
| `sphinx` | Sphinx roles, directives, and cross-document conventions. |
| `symfony` | Symfony documentation and its public extension behavior. |

Choose the narrowest profile that recognizes the source. The selected profile
stays attached to parse, render, lint, fix, format, statistics, and conversion
operations.

`Profile::withExtension()` returns a new profile. Later extensions replace a
handler, pass, rule, or statistics provider with the same normalized name.
Read [Extensions](../extensions.md) before installing trusted behavior.
