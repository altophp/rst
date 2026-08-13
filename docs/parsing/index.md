# Parsing

Use `parse()` when an application needs the document tree, problems, source
positions, or references in addition to rendered output.

```php
use Alto\Rst\Rst;

$result = Rst::sphinx()->parse($source);

$document = $result->document();
$parserProblems = $result->problems();
$references = $result->references();
```

`ParseResult::references()` is lazy. Parsing does not build the graph until a
caller requests it. Parser recovery problems and reference-resolution problems
remain separate reports.

```php
foreach ($result->problems() as $problem) {
    echo $problem->code.': '.$problem->message."\n";
}

foreach ($result->references()->problems() as $problem) {
    echo $problem->code.': '.$problem->message."\n";
}
```

Malformed input recovers into typed problems while retaining source text. A
consumer can decide which problem severities block rendering, conversion, or
persistence.

Read [Profiles](profiles.md) before choosing syntax, [Documents](documents.md)
to traverse nodes, and [Positions](positions.md) before applying source edits.
