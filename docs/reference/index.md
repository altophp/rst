# Reference

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

Read [Links](links.md), [Footnotes](footnotes.md), and
[Substitutions](substitutions.md) for local semantics. Use [Projects](projects.md)
when Sphinx references cross file boundaries.
