# Links

Reference occurrences have one of five statuses: `resolved`, `unresolved`,
`ambiguous`, `circular`, or `deferred` for a project-wide second pass.

```php
use Alto\Rst\Reference\DefinitionKind;
use Alto\Rst\Reference\ReferenceStatus;
use Alto\Rst\Reference\ReferenceType;

foreach ($graph->references(ReferenceType::Hyperlink) as $reference) {
    if (ReferenceStatus::Resolved === $reference->status) {
        $definition = $reference->target;
    }
}

$targets = $graph->definitions(DefinitionKind::Hyperlink);
```

Name lookup collapses whitespace and folds ASCII case while preserving display
names. Explicit empty targets can attach to the semantic block that follows;
`anchorNode()` exposes that carrier for renderers.

`unresolved()` returns every occurrence not currently resolved. Project roles
may remain deferred until a `ProjectReferenceMap` is available. See
[Projects](projects.md).
