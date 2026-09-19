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

## Compare real outcomes

With Composer's autoloader loaded, this script converts four bounded examples:

```php
use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\RstToMarkdown;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;

$inputs = [
    'Heading' => "Guide\n=====\n",
    'Command' => 'Run :command:`composer install`.',
    'Math' => 'Compute :math:`x^2`.',
    'Project' => ".. toctree::\n\n    guide/page\n",
];
$rst = Rst::symfony();

foreach ($inputs as $name => $input) {
    $source = Source::fromString($input);
    $parsed = $rst->parse($input);
    $result = new RstToMarkdown()->convert(
        $parsed->document(),
        $source,
        $rst->profile(),
        ConversionOptions::symfony(),
        $parsed->references(),
    );
    printf("%s: %s; complete=%s; lossless=%s; exact=%s\n",
        $name,
        $result->status()->value,
        $result->isComplete() ? 'yes' : 'no',
        $result->isLossless() ? 'yes' : 'no',
        $result->isExact() ? 'yes' : 'no',
    );
}
```

The output is:

```text
Heading: exact; complete=yes; lossless=yes; exact=yes
Command: tracked; complete=yes; lossless=yes; exact=no
Math: review; complete=yes; lossless=no; exact=no
Project: blocked; complete=no; lossless=no; exact=no
```

The math role loses mathematical semantics even though code text survives.
The `toctree` needs project context; use [Project conversion](projects.md)
with a project map instead of accepting a placeholder as a successful migration.
