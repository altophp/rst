# Patches

Fixing, formatting, and typed editing return byte-accurate `SourcePatch`
values. A patch replaces one half-open range in the original input.

```php
use Alto\Rst\Operation\Diff;

$diff = Diff::between(
    $input,
    $result->bytes,
    'guide.rst',
    'guide.rst',
);

echo $diff->toUnifiedString();
```

Patches are validated, sorted, and applied against the source generation that
created them. Overlapping replacements raise `PatchConflictException`; an
invalid range raises `SourcePatchException`.

An editor exposes `patches()` and `toRst()`. Fix and format results expose
their accepted patches together with changed bytes and reparsed state. Review
the diff before writing user-authored documentation.

Patches are not authorization. The application still decides which document
and path may change. Continue with [Files](files.md) for conflict-safe
persistence.
