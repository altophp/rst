# References

The reference graph resolves targets, hyperlinks, notes, citations, and
substitutions separately from the source-preserving document tree.

```php
use Alto\Rst\Rst;

$result = Rst::sphinx()->parse($source);
$graph = $result->references();
```

The graph contains definitions and occurrences in source order, a status for
every occurrence, incoming and unused indexes, and a problem report independent
from parser recovery.

```php
$target = $graph->target('installation');
$incoming = null === $target ? [] : $graph->incoming($target);
$unused = $graph->unusedDefinitions();
$problems = $graph->problems();
```

Continue with:

- [Links](references/links.md) for targets and hyperlinks.
- [Footnotes](references/footnotes.md) for notes and citations.
- [Substitutions](references/substitutions.md) for bounded replacements.
- [Projects](references/projects.md) when Sphinx references cross file boundaries.

## Public contract

`ReferenceGraph` indexes definitions and occurrences for one document.

| Method | Purpose |
| --- | --- |
| `definitions()` | Select definitions, optionally by kind. |
| `references()` | Select occurrences, optionally by type. |
| `unresolved()` | Return every non-resolved occurrence. |
| `incoming()` | Find occurrences resolving to a definition. |
| `target()` | Resolve a normalized target name. |
| `unusedDefinitions()` | Find unused definitions when coverage permits. |
| `problems()` | Return graph diagnostics. |
| `isCoverageComplete()` | Test whether opaque content limits negative findings. |

`ProjectReferenceMap` accepts graphs keyed by project-relative path. It exposes
per-occurrence resolution, incoming and outgoing edges, project problems,
document paths and titles, and path resolution.

Reference definitions and occurrences retain original `ByteSpan` values.
Rendering and conversion can reuse a prebuilt graph.
