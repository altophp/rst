# Exceptions

Malformed RST is normally represented by parser, reference, lint, or conversion
problems. Exceptions are reserved for invalid API use and unsafe I/O or patch
operations.

| Exception | Boundary |
| --- | --- |
| `InvalidArgumentException` | Invalid public option, range, path, or constructor value. |
| `SourcePatchException` | A patch range does not address the source safely. |
| `PatchConflictException` | Planned patches overlap. |
| `FileReadException` | A requested source file cannot be read safely. |
| `FileWriteException` | A target cannot be replaced safely. |
| `FileConflictException` | A file changed after it was opened. |

All package exceptions implement `RstExceptionInterface`, allowing an
application boundary to catch Alto failures without catching unrelated runtime
exceptions.

Do not convert parser or conversion problems into exceptions merely to stop a
workflow. Inspect the relevant reports and apply the application's acceptance
policy explicitly.
