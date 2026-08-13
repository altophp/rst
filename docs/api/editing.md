# Editing API

`Editor` plans changes against one matching `Source` and `ParseResult`.

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

See [Editing](../editing/index.md), [Patches](../editing/patches.md), and
[Files](../editing/files.md).
