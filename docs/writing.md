# Fix, format, and edit

Alto can fix, format, and edit RST without rendering the complete document
back to source. Every change is a patch over the original byte offsets.
Run the dedicated [lint workflow](lint.md) first when you also need
diagnostics.

## Apply safe fixes

```php
use Alto\Rst\Fix\FixEngine;
use Alto\Rst\Fix\FixOptions;
use Alto\Rst\Profile\Profile;

$result = new FixEngine()->fix(
    $input,
    new FixOptions(
        removeTrailingWhitespace: true,
        maxBlankLines: 2,
        blankLineAfterAnchor: true,
        blankLineBeforeDirectiveBody: true,
        normalizeDefaultRoleAsLiteral: false,
    ),
    Profile::symfony(),
);

$fixed = $result->bytes;
$patches = $result->patches;
$parsed = $result->parseResult;
```

The fixer removes trailing whitespace and excess blank lines. It also inserts
safe blank separators after internal anchors and before simple directive
bodies. It leaves the complete input untouched when the parser recovered
from an error and protects directive content, comments, literal blocks, and
tables.

`normalizeDefaultRoleAsLiteral` is deliberately disabled by default. When
enabled, unqualified interpreted text such as <code>`cache`</code> becomes
the inline literal <code>``cache``</code>. Explicit roles, links, existing
literals, and protected regions stay unchanged. This is a policy choice
because standard RST assigns a default role to single-backtick text.

The result is reparsed, but the caller still decides whether parser and
reference problems permit persistence.

## Format conservatively

```php
use Alto\Rst\Format\FormatOptions;
use Alto\Rst\Format\Formatter;
use Alto\Rst\Profile\Profile;

$result = new Formatter()->format(
    $input,
    new FormatOptions(
        normalizeSectionAdornments: true,
        bulletMarker: '-',
        alignSimpleTables: true,
        lineWidth: 80,
    ),
    Profile::symfony(),
);
```

The formatter has four passes:

- make section underlines and overlines match the displayed title width;
- normalize safe ASCII bullet lists to `-`, `*`, or `+`;
- align rectangular, one-line simple tables;
- wrap selected prose at an opt-in character width.

It skips ambiguous Unicode title widths and list boundaries that could
change the parsed structure. Paragraph wrapping accepts only plain inline
text and standalone URLs, preserves indentation and line endings, and
abandons the pass on parser, reference, or semantic drift. Eligible
paragraphs may be direct children of a document, section, or list item.
Standard admonitions are eligible only when their uniformly indented body
reparses as one simple paragraph. Other directives, literal blocks, block
quotes, escapes, markup, and unbreakable tokens remain unchanged.

Simple-table alignment recalculates the column widths without changing cell
bytes. It accepts only rectangular tables whose cells occupy one physical
line and have no row or column spans. Multi-line cells, empty cells, grid
tables, parser recovery, and Unicode layouts whose visual columns disagree
with RST parser columns remain unchanged. Every accepted table and prose
candidate is reparsed and compared with the original semantic structure.
Active formatter extension passes use the same structural guard.

Formatting is idempotent:

```php
$first = new Formatter()->format($input);
$second = new Formatter()->format($first->bytes);

assert([] === $second->patches);
```

Both engines are dry-run by construction: they return changed bytes and
patches but never write a file. Preview a unified diff before persistence:

```php
use Alto\Rst\Operation\Diff;

$diff = Diff::between($input, $result->bytes, 'guide.rst', 'guide.rst');
echo $diff->toUnifiedString();
```

## Plan typed edits

Create an editor from the exact `Source` and `ParseResult` that produced the
section handles:

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
$patches = $editor->patches();
```

Replacing a section body includes its nested subsections but never the next
sibling section. Overlapping edits fail before the editor changes its patch
plan.

Directive options use the parsed directive handle:

```php
use Alto\Rst\Node\Directive;

foreach ($parsed->document()->descendants() as $node) {
    if ($node instanceof Directive && 'code-block' === $node->name) {
        $editor
            ->setDirectiveOption($node, 'linenos')
            ->removeDirectiveOption($node, 'force');
    }
}
```

`setDirectiveOption()` preserves the existing option name, indentation, line
ending, and continuation layout when it replaces a value. It inserts a new
field beside existing options when the option is absent. Duplicate option
fields are rejected because their intended target is ambiguous.

`renameTarget()` changes one explicit hyperlink target, its resolved local
references, and indirect aliases as one transactional patch plan. It rejects
anonymous targets, incomplete reference coverage, duplicate definitions,
name collisions, and source forms that cannot be rewritten safely.

After applying and reparsing edited bytes, create a new `Editor` and use
section handles from the new parse result. Old handles always refer to the
original byte offsets.

## Diff and persist

`RstFile` owns one parsed file, its editor, and the fingerprint captured when
the file was opened:

```php
use Alto\Rst\File\RstFile;
use Alto\Rst\Profile\Profile;

$file = RstFile::open(__DIR__.'/guide.rst', Profile::symfony());
$editor = $file->editor();

// Plan edits with handles from $file->parsed().

echo $file->diff()->toUnifiedString();
$file->save();
```

`save()`:

- compares the current file with the opening fingerprint;
- writes through a temporary file in the target directory;
- preserves the existing permission bits;
- atomically renames the temporary file;
- reparses the saved bytes and rebases the editor.

External changes raise `FileConflictException` without discarding pending
edits. Symlink and non-regular targets are never replaced. `saveAs()` refuses
an existing unopened target by default.

`SaveOptions` can disable atomicity or compare-before-write explicitly.
Applications still own authorization, backups, and recovery policy.
