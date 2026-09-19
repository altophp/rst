# Editing

`Editor` plans typed changes against the exact `Source` and `ParseResult` that
produced the selected node handles. With Composer's autoloader loaded, this
example replaces one section body and appends a section:

```php
use Alto\Rst\Edit\Editor;
use Alto\Rst\Node\Section;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;

$input = "Guide\n=====\n\nOriginal body.\n";
$source = Source::fromString($input);
$parsed = Rst::symfony()->parse($source->bytes);
$section = $parsed->document()->children()[0];

if (!$section instanceof Section) {
    throw new RuntimeException('The first block is not a section.');
}

$editor = new Editor($source, $parsed);
$editor->replaceSectionBody($section, "New body.\n");
$editor->insertTopLevel("Appendix\n========\n");

$rst = $editor->toRst();
echo $rst;
```

The output keeps the original heading and replaces only its body before
appending the requested section:

```rst
Guide
=====

New body.

Appendix
========
```

`replaceSectionBody()` includes nested subsections but not the next sibling.
`setDirectiveOption()` and `removeDirectiveOption()` preserve option layout.
`renameTarget()` updates one explicit target and its resolved local references
as a transactional patch plan.

Overlapping or ambiguous edits fail before changing the plan. After applying
or saving edits, parse again and create an editor with the new handles.

Continue with:

- [Formatting](editing/formatting.md) to normalize conservative layout.
- [Patches](editing/patches.md) to inspect exact byte changes and diffs.
- [Files](editing/files.md) to save with conflict detection.

## Public contract

| Method | Purpose |
| --- | --- |
| `replaceSectionBody()` | Replace one section body. |
| `setDirectiveOption()` | Insert or replace a directive option. |
| `removeDirectiveOption()` | Remove one unambiguous option. |
| `renameTarget()` | Rename a target and resolved local references. |
| `insertTopLevel()` | Append root-level RST. |
| `toRst()` | Apply the current plan in memory. |
| `patches()` | Return exact `SourcePatch` values. |

`Diff::between()` builds a unified diff between two byte strings.

`RstFile::open()` returns a path-backed document with `source()`, `parsed()`,
`editor()`, `toRst()`, `diff()`, `save()`, and `saveAs()`. Save operations use
fingerprint conflict detection and reject symbolic-link or non-regular targets.
