# Positions

Nodes and problems use byte offsets into the original input. These offsets are
stable for the lifetime of one parse result and do not count Unicode code
points or display columns.

```php
foreach ($result->problems() as $problem) {
    if (null !== $problem->span) {
        $bytes = substr(
            $source,
            $problem->span->start,
            $problem->span->length,
        );
    }
}
```

Use positions only with the exact source that produced the parse result.
`ParseResult::matchesSource()` can verify a `Source` value before an editor or
consumer combines both objects.

Tables and structured directive bodies retain document-level spans. Detached
or normalized inline segments may not expose an original document range. A
consumer must not reinterpret local offsets as document offsets.

After applying patches or saving a file, parse again and obtain new nodes and
positions. Continue with [Patches](../editing/patches.md) for edit ordering and
conflict behavior.
