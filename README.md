# Alto Rst

Alto Rst parses reStructuredText into the ROM, a source-positioned object
model you can lint, format, edit, and convert to safe HTML, preserving
everything you don't touch.

[![CI](https://github.com/altophp/rst/actions/workflows/CI.yml/badge.svg)](https://github.com/altophp/rst/actions/workflows/CI.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.4-777bb4.svg)](composer.json)

The core has no runtime Composer dependencies. It supports docutils RST,
Sphinx syntax, and the conventions used by Symfony documentation. Trusted
extensions can add directives, roles, lint rules, fixes, formatter passes,
statistics, and conversion mappings.

The full guide set lives under [`docs/`](docs/index.md).

| Need | Start with |
| --- | --- |
| Convert RST to safe HTML | `Rst::sphinx()->toHtml($source)` |
| Parse and inspect a document | `Rst::sphinx()->parse($source)` |
| Inspect targets and references | `$result->references()` |
| Lint a document | `Linter::lint()` |
| Fix, format, or edit source | `FixEngine`, `Formatter`, and `Editor` |
| Convert RST and Markdown | `RstToMarkdown` and `MarkdownToRst` |
| Convert a documentation directory | `ProjectConverter::convertDirectory()` |
| Add trusted behavior | `Profile::withExtension()` |

## Installation

```bash
composer require alto/rst
```

Requires PHP 8.4 or newer. No Python or Sphinx process is involved at runtime.

## Render to HTML

```php
use Alto\Rst\Rst;

$source = "Run ``composer install``.\n";
$html = Rst::sphinx()->toHtml($source);
```

The result is:

```html
<p>Run <code>composer install</code>.</p>
```

Direct rendering is the shortest path when HTML is the only result you need.
Output is safe by default: text is escaped, unsafe URL schemes are filtered,
and file-reading directives stay disabled.

Choose the narrowest profile that matches the source:

```php
Rst::docutils();
Rst::sphinx();
Rst::symfony();
```

See [Installation](docs/install.md), [Parse and
render](docs/parse-and-render.md), and [Security](docs/security.md) for
setup, profile, and rendering policies.

## Parse a document when you need more

Parsing keeps the document tree, original byte positions, references, and
recovery problems available for later operations:

```php
use Alto\Rst\Rst;

$source = <<<'RST'
    .. _installation:

    Installation
    ============

    Read the :ref:`installation` section.
    RST;

$result = Rst::sphinx()->parse($source);

$document = $result->document();
$parserProblems = $result->problems();
$references = $result->references();
$referenceProblems = $references->problems();
```

Malformed input recovers into typed parser problems instead of losing source
text. Reference resolution has its own report, so syntax recovery and broken
document links remain distinguishable.

The same parsed model powers linting, project-wide reference resolution,
source-preserving edits, and conversion. Maintenance operations return exact
patches before anything is saved. Project conversion discovers `.rst` files
recursively, resolves cross-document Sphinx links, and aggregates every
unsupported, lossy, or approximate mapping for review.
Resolved footnotes, citations, and substitutions have explicit Markdown
mappings, including collision-safe anchors across expanded include files.
Code fence language names such as `html+twig` remain unchanged.

The documentation covers this in more depth: [Lint RST](docs/lint.md) to run
and configure the recommended rules, [Convert documents](docs/convert.md) to
convert individual documents or complete projects between RST and Markdown,
and [Fix, format, and edit](docs/writing.md) for conservative fixes,
formatting, typed edits, diffs, and conflict-safe file persistence.

## Extend

Trusted extensions add directives, roles, lint rules, fixes, formatter
passes, statistics, and conversion mappings through compiled profile
contracts. Read [Extensions](docs/extensions.md) for the extension contracts
and [Reference graph](docs/references.md) for how local and project-wide
targets, links, notes, citations, and substitutions resolve.

## Documentation

- [Documentation index](docs/index.md): browse the complete guide set.
- [Security](docs/security.md): control URLs, raw HTML, includes, and other
  file-reading constructs.

## Development

```bash
composer qa        # phpstan (max), php-cs-fixer, phpunit
composer tests     # phpunit only
composer coverage  # phpunit with a 97% line-coverage floor
```

The suite runs against a pinned docutils 0.23 fixture corpus committed to the
repository. No Python or Sphinx process is involved, at runtime or at test time.

Set `ALTO_RST_UX_CORPUS` to a Symfony UX checkout to also run the corpus
conversion tests; they skip when it is unset.

## License

Alto Rst is available under the [MIT License](LICENSE).
