# Reference graph

reStructuredText resolves links, targets, notes, citations, and
substitutions across the whole document. Sphinx adds references that may
cross file boundaries. Alto models those relationships separately from the
source-preserving document tree.

## Build the document graph

```php
use Alto\Rst\Rst;

$result = Rst::sphinx()->parse($source);
$graph = $result->references();
```

The graph contains:

- definitions in source order;
- reference occurrences in source order;
- a status for every occurrence;
- incoming-reference and unused-definition indexes;
- resolution problems independent of parser recovery problems.

## Resolution status

```php
use Alto\Rst\Reference\ReferenceStatus;

foreach ($graph->references() as $reference) {
    if (ReferenceStatus::Resolved === $reference->status) {
        $definition = $reference->target;
    }
}
```

The possible statuses are:

| Status | Meaning |
| --- | --- |
| `resolved` | The target is unique and available. |
| `unresolved` | No valid target exists. |
| `ambiguous` | More than one definition could win. |
| `circular` | An indirect target or substitution contains a cycle. |
| `deferred` | A Sphinx role needs the project-wide second pass. |

`unresolved()` returns every occurrence whose status is not `resolved`.

## Query by type

```php
use Alto\Rst\Reference\DefinitionKind;
use Alto\Rst\Reference\ReferenceType;

$links = $graph->references(ReferenceType::Hyperlink);
$notes = $graph->definitions(DefinitionKind::Footnote);
$target = $graph->target('installation');
$incoming = null === $target ? [] : $graph->incoming($target);
$unused = $graph->unusedDefinitions();
```

Name lookup collapses whitespace and folds ASCII case. Display names remain
unchanged. Unicode case folding is not part of the current platform
contract.

## Reference coverage

The graph analyzes normal block content, definition terms, simple and grid
table cells through their segmented cache, and directive bodies declared as
`DirectiveBodyKind::Blocks`. Nested lists, directives, targets, and prose use
the same typed traversal and original byte spans as the outer document.

Literal bodies are intentionally skipped because code and data do not contain
RST references. A body declared `Opaque`, including an unknown directive with
content, makes coverage incomplete. Unexpected content retained on a
bodyless directive also makes coverage incomplete.

```php
if (!$graph->isCoverageComplete()) {
    // Do not conclude that an unreferenced definition is unused.
}
```

The recommended unused-definition lint rule performs this check
automatically. Positive findings such as a resolved or forbidden link still
come from typed occurrences with original byte spans.

## Definitions and rendered anchors

An explicit empty target can attach to the semantic block that follows it:

```rst
.. _installation:

Installation
============
```

The graph keeps the explicit definition as the stable identity and exposes
its carrier through `anchorNode()`. This lets `incoming()` and
`unusedDefinitions()` remain coherent while the renderer places one or more
HTML anchors on the following block.

Presentation IDs are not stored in the graph. HTML and Markdown consumers
derive their own IDs.

## Footnotes, citations, and substitutions

The graph supports explicit, auto-numbered, labeled auto-numbered, and
symbol footnotes. Display labels belong to definitions, so an unreferenced
auto footnote still has a stable label.

Substitution resolution supports standard `replace`, `unicode`, and `image`
definitions. Expansion:

- follows nested substitutions;
- ignores escaped pipes and inline literals;
- preserves linked `|name|_` and `|name|__` forms;
- reports cycles and missing names;
- stops at 256 levels or 1 MiB of expanded text.

The expansion limits protect consumers of untrusted input from exponential
replacement graphs.

## Resolve a Sphinx project

Parse every source first, then build a map from project-relative paths:

```php
use Alto\Rst\Reference\ProjectReferenceMap;
use Alto\Rst\Rst;

$rst = Rst::sphinx();
$graphs = [];

foreach ($sourcesByPath as $path => $source) {
    $graphs[$path] = $rst->parse($source)->references();
}

$project = new ProjectReferenceMap($graphs);
```

The map resolves `:ref:` in the global explicit-label namespace and `:doc:`
against canonical docnames. It supports `.rst` suffixes, root-relative
docnames, relative paths, and directory `index` aliases.

```php
$outgoing = $project->outgoing('guide/start.rst');
$incoming = $project->incoming('reference/configuration.rst');
$problems = $project->problems();
```

`resolution($path, $occurrence)` returns the result recorded for an
occurrence from the graph used to build the map. `resolveReference($path,
$occurrence)` performs the same semantic lookup for a Sphinx reference from
a re-parsed fragment. It does not mutate the indexed incoming, outgoing, or
problem reports.

Project labels must be unique. The optional second constructor argument
enables the implicit section labels used by projects with Sphinx
`autosectionlabel` semantics:

```php
$project = new ProjectReferenceMap($graphs, implicitSectionLabels: true);
```

Explicit labels win. An implicit match in the source document wins over
equally named sections in other files. A unique matching docname or section
slug resolves; ambiguous or missing names remain project problems.

`ConversionOptions::symfony()` enables this policy when `ProjectConverter`
builds the map.

`ProjectReferenceMap` never reads files. The caller owns discovery, source
loading, and path policy.
