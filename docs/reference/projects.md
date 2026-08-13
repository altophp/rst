# Projects

`ProjectReferenceMap` resolves Sphinx `:ref:` and `:doc:` roles across a map of
project-relative document paths.

```php
use Alto\Rst\Reference\ProjectReferenceMap;
use Alto\Rst\Rst;

$rst = Rst::sphinx();
$graphs = [];

foreach ($sourcesByPath as $path => $source) {
    $graphs[$path] = $rst->parse($source)->references();
}

$project = new ProjectReferenceMap($graphs);
```

The map supports `.rst` suffixes, root-relative docnames, relative paths, and
directory `index` aliases. It provides `outgoing()`, `incoming()`,
`problems()`, and per-occurrence resolution.

```php
$outgoing = $project->outgoing('guide/start.rst');
$incoming = $project->incoming('reference/configuration.rst');
```

Set `implicitSectionLabels: true` only for projects that use Sphinx
`autosectionlabel` semantics. `ProjectReferenceMap` never discovers or reads a
file; the caller owns the source map and path policy.
