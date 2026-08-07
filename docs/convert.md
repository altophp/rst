# Convert documents

Alto translates between reStructuredText and a deliberately scoped Markdown
model. Every unsupported, lossy, or approximated mapping is recorded in a
conversion report.

## Convert RST to Markdown

```php
use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\ConversionStatus;
use Alto\Rst\Convert\IssueKind;
use Alto\Rst\Convert\RstToMarkdown;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;

$source = Source::fromString($input);
$parsed = Rst::symfony()->parse($source->bytes);

$result = new RstToMarkdown()->convert(
    $parsed->document(),
    $source,
    Profile::symfony(),
    ConversionOptions::symfony(),
    $parsed->references(),
);

echo $result->output;

if (ConversionStatus::Blocked === $result->status()) {
    foreach ($result->report->issues as $issue) {
        if (IssueKind::Unsupported === $issue->kind) {
            echo $issue->construct.': '.$issue->message;
        }
    }
}

$status = $result->status();
$countsByKind = $result->report->countsByKind();
$countsByKindAndConstruct = $result->report->countsByKindAndConstruct();
```

Inspect the report after every conversion. Its fidelity contract is:

- `isComplete()` means every source construct produced a supported target
  equivalent. Unsupported constructs may still leave visible placeholders.
- `isLossless()` means no source information is known to have been discarded.
- `isExact()` means the report contains no unsupported, lossy, or approximated
  mapping.
- `status()` returns the highest required action: `Blocked`, `Review`,
  `Tracked`, or `Exact`.

An approximation is complete and lossless, but not exact. A lossy mapping is
complete, but not lossless. An unsupported mapping is neither complete nor
lossless.

## Resolve cross-document roles

Pass the graph, project map, and current source path to convert Sphinx
`:ref:` and `:doc:` roles into Markdown links:

```php
use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\RstToMarkdown;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Reference\ProjectReferenceMap;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;

$sourcesByPath = [
    'guide/start.rst' => "Start\n=====\n\nSee :doc:`install`.\n",
    'guide/install.rst' => "Install\n=======\n",
];
$rst = Rst::sphinx();
$parsedByPath = [];
$graphsByPath = [];

foreach ($sourcesByPath as $path => $bytes) {
    $parsedByPath[$path] = $rst->parse($bytes);
    $graphsByPath[$path] = $parsedByPath[$path]->references();
}

$currentPath = 'guide/start.rst';
$currentSource = Source::fromString($sourcesByPath[$currentPath]);
$current = $parsedByPath[$currentPath];
$project = new ProjectReferenceMap($graphsByPath);

$conversion = new RstToMarkdown()->convert(
    $current->document(),
    $currentSource,
    Profile::sphinx(),
    new ConversionOptions(),
    $current->references(),
    $project,
    $currentPath,
);
```

This API performs the semantic second pass without reading the filesystem.
`ProjectConverter` reads the same setting from
`ConversionOptions::symfony()`. Explicit labels still win, local section
labels win over equally named remote sections, and ambiguous matches remain
unresolved.

## Convert a directory

`ProjectConverter` provides the recursive, in-memory driver:

```php
use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\IssueKind;
use Alto\Rst\Convert\ProjectConverter;
use Alto\Rst\Profile\Profile;

$result = new ProjectConverter()->convertDirectory(
    __DIR__.'/rst',
    Profile::symfony(),
    ConversionOptions::symfony(),
);

foreach ($result->files as $file) {
    echo $file->sourcePath.' -> '.$file->targetPath;
    $effectiveProblems = $file->referenceProblems;
    $sourceOnlyProblems = $file->sourceReferenceProblems;
}

$markdownByPath = $result->outputs();
$conversionDebt = $result->report->countsByConstruct();
$projectProblems = $result->projectReferenceProblems;
$parserDiagnostics = $result->parseProblems()->countsBySeverity();
$referenceDiagnostics = $result->referenceProblems()->countsBySeverity();
$reviewFiles = $result->filesWithIssueKind(IssueKind::Lossy);
```

The driver:

- discovers `.rst` files recursively in deterministic path order;
- skips symbolic links;
- parses every file before building the project reference map;
- returns per-file parser, reference, and conversion reports;
- returns one aggregate conversion report;
- exposes generic conversion-status, issue-kind, and diagnostic queues;
- never writes output files.

`referenceProblems` is the effective conversion-context report.
`sourceReferenceProblems` preserves the raw source-only graph report. They
differ only when an authorized include supplies a target that the standalone
source file cannot see. The converter removes an unresolved finding only
after it resolves that exact source span from the indexed include tree.

Caller-supplied sources are also supported:

```php
$result = new ProjectConverter()->convertSources($sourcesByRelativePath);
```

Paths must be relative and end in `.rst`. Traversal above the project root,
absolute paths, null bytes, duplicate source paths, and duplicate Markdown
target paths are rejected.

## Expand trusted includes

`include` is inert by default. Directory conversion can expand it only when
the caller supplies a resolved root:

```php
use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\ProjectConverter;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Security\FileAccessPolicy;

$root = __DIR__.'/rst';
$policy = FileAccessPolicy::rootedAt($root);

$result = new ProjectConverter()->convertDirectory(
    $root,
    Profile::symfony(),
    ConversionOptions::symfony($policy),
);
```

A leading `/` in the directive is relative to `$root`. Other paths resolve
beside the including source. The policy:

- canonicalizes dot segments;
- rejects traversal above the root and all URLs;
- checks the resolved target to prevent symlink escapes;
- limits nested includes to 32 levels by default;
- rejects include cycles;
- never writes the expanded output.

Included `.rst.inc` files do not become standalone project outputs. Their
content is parsed and converted where the directive appears. The report
records `directive:include` as lossy because Markdown has no include
relationship or file boundary. Section and explicit targets from the full
authorized include tree are indexed before writing, so a reference may
resolve even when it appears before its include directive.

Include options such as `:start-line:` are not implemented. Supplying one
leaves an inert placeholder and records an unsupported issue instead of
silently dropping the option. `literalinclude` and file-reading table options
remain disabled.

## Authorize raw HTML separately

The include root does not authorize raw HTML. A trusted migration must opt in
separately:

```php
$options = ConversionOptions::symfony(
    fileAccessPolicy: $policy,
    allowRawHtml: true,
);
```

Only `.. raw:: html` with an inline body and no options is embedded. Other
raw formats and option-bearing directives remain unsupported. HTML rendering
never uses this conversion option and never passes through raw markup.

## Symfony configuration blocks

The Symfony extension converts `configuration-block` bodies through their
nested RST. Markdown has no tab set, so YAML, XML, PHP, and other code blocks
are written in source order. Each group records an approximated
`directive:configuration-block` issue. Nested conversion retains the owning
document's targets, project map, file path, and include stack.

The Symfony project converter also maps:

- `toctree` to a verified Markdown link list, including explicit `:glob:`
  expansion through the project map;
- `sidebar` and `topic` to quoted asides;
- `screencast` to a tip callout;
- `class` by keeping the following block and reporting the dropped CSS class.

Missing toctree targets, unmatched globs, and wildcard entries without
`:glob:` remain unsupported.

## Footnotes, citations, and substitutions

Resolved footnotes use GitHub Markdown footnote syntax. Resolved citations
become links to anchored citation paragraphs because GitHub Markdown has no
native citation construct; the report records that approximation.

Resolved `replace` and `unicode` substitutions expand inline. Image
substitutions become Markdown images. Unresolved or unsupported references
retain their visible source text and remain reported as lossy. Expanded
include files namespace their footnote and citation anchors so definitions
from separate files cannot collide in the generated document.

## Convert Markdown to RST

```php
use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\Markdown\MarkdownReader;
use Alto\Rst\Convert\MarkdownToRst;

$markdown = new MarkdownReader()->read($input);
$result = new MarkdownToRst()->convert(
    $markdown,
    ConversionOptions::symfony(),
);

echo $result->output;
```

The Markdown reader intentionally supports the subset written by Alto plus
the constructs found in the Symfony documentation workload. It is not a
general-purpose CommonMark implementation.

## Current conversion boundary

Implemented:

- headings, paragraphs, inline markup, links, lists, block quotes, and
  definition lists, simple tables, and grid tables;
- resolved footnotes, citations, replacement, Unicode, and image
  substitutions;
- code blocks with source fence-language names preserved;
- admonitions and common Sphinx directives;
- Symfony `configuration-block` through the extension contract;
- Symfony PHP symbol roles through extension role handlers;
- verified `toctree`, `sidebar`, `topic`, `screencast`, and `class` mappings;
- explicitly authorized, root-confined include expansion;
- separately authorized raw HTML preservation for trusted migrations;
- reference-style link preservation;
- project-aware `:ref:` and `:doc:`, including Symfony implicit section
  labels;
- issue accounting in both directions.
