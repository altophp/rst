# Parse and render RST

This guide is for PHP developers who need to parse or render
reStructuredText without running Python or Sphinx at runtime. Complete the
[installation](install.md) first.

## Choose a profile

A profile defines the directives and roles recognized by the parser and
renderer.

```php
use Alto\Rst\Rst;

$docutils = Rst::docutils();
$sphinx = Rst::sphinx();
$symfony = Rst::symfony();
```

Use `docutils` for standard RST, `sphinx` for Sphinx roles and directives,
and `symfony` for the conventions found in Symfony documentation.
The Symfony profile is composed with a public extension that handles
`configuration-block` and can contribute role, lint, fix, format, and
statistics behavior through the same contracts.

## Render HTML

```php
use Alto\Rst\Rst;

$source = <<<'RST'
    Guide
    =====

    Read the `project site <https://example.com>`_.
    RST;

$html = Rst::sphinx()->toHtml($source);
```

The renderer escapes text and checks link schemes through `HtmlPolicy`.
Unknown constructs degrade conservatively instead of becoming raw HTML.

## Parse once and inspect

Use `parse()` when more than one consumer needs the document:

```php
use Alto\Rst\Rst;

$result = Rst::symfony()->parse($source);

$document = $result->document();
$parserProblems = $result->problems();
$references = $result->references();
```

`ParseResult::references()` is lazy. Parsing a document does not build the
reference graph until a caller asks for it.

Parser problems and reference problems are separate:

```php
foreach ($result->problems() as $problem) {
    echo $problem->code.': '.$problem->message;
}

foreach ($result->references()->problems() as $problem) {
    echo $problem->code.': '.$problem->message;
}
```

Every reported span uses byte offsets into the original input.

Directive nodes preserve their raw body span and expose typed children when
the active profile declares ordinary RST block content:

```php
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\DirectiveBodyKind;

foreach ($document->descendants() as $node) {
    if ($node instanceof Directive
        && DirectiveBodyKind::Blocks === $node->bodyKind
    ) {
        $bodyNodes = $node->children();
    }
}
```

Literal bodies such as code blocks remain raw. Unknown or extension-specific
syntax remains opaque until its `DirectiveSpec` declares a body kind.

## Next steps

- [Lint RST](lint.md) before accepting source changes.
- [Convert documents](convert.md) between RST and Markdown.
- Read the [reference graph](references.md) guide for document and
  project-wide resolution.
- [Fix, format, and edit](writing.md) source with minimal byte patches.
