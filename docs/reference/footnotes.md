# Footnotes

The graph resolves explicit, auto-numbered, labeled auto-numbered, and symbol
footnotes together with citations.

```php
use Alto\Rst\Reference\DefinitionKind;
use Alto\Rst\Reference\ReferenceType;

$footnotes = $graph->definitions(DefinitionKind::Footnote);
$citations = $graph->definitions(DefinitionKind::Citation);
$references = $graph->references(ReferenceType::Footnote);
```

Display labels belong to definitions, so an unreferenced automatic footnote
still receives a stable label. Incoming indexes connect every resolved
occurrence to its definition.

Rendering and conversion reuse the graph rather than resolving labels again.
Missing, duplicate, or ambiguous definitions stay visible through reference
problems and conversion reports.
