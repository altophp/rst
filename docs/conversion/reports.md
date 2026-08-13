# Reports

Every conversion result exposes output and a `ConversionReport`. Treat the
report as a migration work list, not as optional logging.

```php
$status = $result->status();
$complete = $result->isComplete();
$lossless = $result->isLossless();
$exact = $result->isExact();
$counts = $result->report->countsByKindAndConstruct();
```

| Issue kind | Meaning | Status |
| --- | --- | --- |
| `unsupported` | No supported target representation exists. | Blocked |
| `lossy` | Output exists but source information was discarded. | Review |
| `approximated` | Output preserves intent through a different construct. | Tracked |

`isComplete()` excludes unsupported mappings. `isLossless()` also excludes
lossy mappings. `isExact()` requires an empty report.

Project results aggregate all file reports and keep parser, effective
reference, and project-reference problems separate. Review those diagnostics
even when conversion itself is exact.
