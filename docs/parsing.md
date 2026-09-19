# Parsing

Use `parse()` when an application needs the document tree, problems, source
positions, or references in addition to rendered output.

```php
use Alto\Rst\Rst;

$result = Rst::sphinx()->parse($source);

$document = $result->document();
$parserProblems = $result->problems();
$references = $result->references();
```

`ParseResult::references()` is lazy. Parsing does not build the graph until a
caller requests it. Parser recovery problems and reference-resolution problems
remain separate reports.

```php
foreach ($result->problems() as $problem) {
    echo $problem->code.': '.$problem->message."\n";
}

foreach ($result->references()->problems() as $problem) {
    echo $problem->code.': '.$problem->message."\n";
}
```

Malformed input recovers into typed problems while retaining source text. A
consumer can decide which problem severities block rendering, conversion, or
persistence.

Continue with the guide that matches the information you need:

- [Profiles](parsing/profiles.md) compares docutils, Sphinx, and Symfony syntax.
- [Documents](parsing/documents.md) traverses the parsed object model.
- [Positions](parsing/positions.md) relates nodes and problems to source bytes.

## Public contract

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
