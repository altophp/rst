# Editing

`Editor` plans typed changes against the exact `Source` and `ParseResult` that
produced the selected node handles.

```php
use Alto\Rst\Edit\Editor;
use Alto\Rst\Node\Section;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;

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
```

`replaceSectionBody()` includes nested subsections but not the next sibling.
`setDirectiveOption()` and `removeDirectiveOption()` preserve option layout.
`renameTarget()` updates one explicit target and its resolved local references
as a transactional patch plan.

Overlapping or ambiguous edits fail before changing the plan. After applying
or saving edits, parse again and create an editor with the new handles.

Read [Patches](patches.md) to inspect the plan and [Files](files.md) before
persisting it.
