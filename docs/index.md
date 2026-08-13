# Alto RST

Alto RST parses reStructuredText into a source-positioned object model and
renders safe HTML without running Python or Sphinx. The same model supports
linting, formatting, editing, references, and conversion.

```php
use Alto\Rst\Rst;

$html = Rst::sphinx()->toHtml("Guide\n=====\n\nWelcome.\n");
```

## Start

- [Installation](installation.md): install the package and verify the runtime.
- [Getting started](getting-started.md): render and inspect one document.

## Parsing

- [Parsing](parsing/index.md): parse once and inspect problems.
- [Profiles](parsing/profiles.md): choose docutils, Sphinx, or Symfony syntax.
- [Documents](parsing/documents.md): traverse the object model.
- [Positions](parsing/positions.md): relate nodes and problems to source bytes.

## Rendering

- [Rendering](rendering/index.md): turn a parsed document into output.
- [HTML](rendering/html.md): render safe semantic HTML.
- [Policy](rendering/policy.md): control URL schemes and raw content.

## Linting

- [Linting](linting/index.md): report document problems.
- [Configuration](linting/configuration.md): select and configure rules.
- [Fixes](linting/fixes.md): apply conservative safe corrections.
- [Rules](linting/rules.md): browse the built-in rule catalog.
- [Custom rules](linting/custom.md): add an application rule.

## Editing

- [Editing](editing/index.md): plan typed source changes.
- [Formatting](editing/formatting.md): normalize conservative layout.
- [Patches](editing/patches.md): inspect exact byte changes and diffs.
- [Files](editing/files.md): save with conflict detection.

## Conversion

- [Conversion](conversion/index.md): choose a conversion direction.
- [To Markdown](conversion/to-markdown.md): convert RST safely.
- [To RST](conversion/to-rst.md): convert supported Markdown.
- [Projects](conversion/projects.md): convert complete documentation trees.
- [Reports](conversion/reports.md): review lossy or approximate mappings.

## Reference

- [Reference](reference/index.md): build and query a graph.
- [Links](reference/links.md): resolve targets and hyperlinks.
- [Footnotes](reference/footnotes.md): resolve notes and citations.
- [Substitutions](reference/substitutions.md): expand bounded replacements.
- [Projects](reference/projects.md): resolve Sphinx links across files.

## Extensions

- [All extensions](extensions/index.md): inspect bundled profile behavior.
- [Extension points](extensions/extension-points.md): choose a public contract.
- [Custom extension](extensions/custom.md): register trusted behavior.

## Security

- [Security](security.md): control files, URLs, raw HTML, and expansion limits.

## API

- [Overview](api/index.md): map the public surface by task.
- [Parsing](api/parsing.md): entry points, profiles, and parse results.
- [Rendering](api/rendering.md): HTML renderer and policies.
- [Quality](api/quality.md): linter, fixer, and formatter.
- [Editing](api/editing.md): editor, patches, diffs, and files.
- [Conversion](api/conversion.md): document and project converters.
- [References](api/references.md): local and project graphs.
- [Extensions](api/extensions.md): extension contribution contracts.
- [Exceptions](api/exceptions.md): recoverable public failures.

HTML output escapes text, filters unsafe URLs, and keeps file-reading
constructs disabled by default. Read [Security](security.md) before granting
additional authority.
