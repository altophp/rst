# Projects

`ProjectConverter` parses every RST source, builds one project reference map,
then converts each document with that shared context. It never writes output.

```php
use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\ProjectConverter;
use Alto\Rst\Profile\Profile;

$result = new ProjectConverter()->convertDirectory(
    __DIR__.'/docs',
    Profile::symfony(),
    ConversionOptions::symfony(),
);

foreach ($result->outputs() as $path => $markdown) {
    // Review and persist through an application-owned workflow.
}
```

`convertSources()` accepts caller-supplied bytes keyed by project-relative RST
path. Directory conversion discovers regular `.rst` files recursively and
skips symbolic links.

The two-pass map resolves `:ref:` and `:doc:` links, including optional
implicit section labels. The result keeps per-file parse, reference, and
conversion diagnostics. See [Reports](reports.md) and
[Project references](../reference/projects.md).
