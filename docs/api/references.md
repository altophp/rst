# References API

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

See [Reference](../reference/index.md) and [Projects](../reference/projects.md).
