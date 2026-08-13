# Parsing API

`Rst` combines a profile with the parser and renderer entry points.

| Method | Return | Purpose |
| --- | --- | --- |
| `profile()` | `Profile` | Return the active profile. |
| `profileName()` | `string` | Return its public name. |
| `parse(string $source)` | `ParseResult` | Parse a source string. |
| `toHtml(string $source, ?RenderOptions $options = null)` | `string` | Parse and render directly. |

`ParseResult` provides `document()`, `problems()`, `matchesSource()`, and lazy
`references()`.

`Source::fromString()` retains exact bytes, line endings, and a possible BOM.
Nodes expose `span()` values over those bytes. Container nodes expose
`children()` and depth-first `descendants()` traversal.

`Profile::docutils()`, `Profile::sphinx()`, and `Profile::symfony()` create
profile values. `withExtension()` returns a new profile with trusted behavior.

See [Parsing](../parsing/index.md), [Documents](../parsing/documents.md), and
[Positions](../parsing/positions.md) for usage constraints.
