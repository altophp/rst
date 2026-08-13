# Rendering

Use `Rst::toHtml()` for one direct result. Use `HtmlRenderer` with an existing
parse result when another consumer already owns the document and source.

```php
use Alto\Rst\Rst;

$html = Rst::sphinx()->toHtml($source);
```

Direct rendering parses with the selected profile, builds references, and
emits HTML under the safe default policy.

When a parse result is already available:

```php
use Alto\Rst\Render\HtmlRenderer;
use Alto\Rst\Render\RenderOptions;
use Alto\Rst\Source\Source;

$sourceObject = Source::fromString($source);
$options = new RenderOptions(profile: $rst->profile());

$html = new HtmlRenderer()->render(
    $result->document(),
    $sourceObject,
    $options,
    $result->references(),
);
```

The document and `Source` must describe the same bytes. Pass the graph when it
has already been built so rendering does not repeat that work.

Read [HTML](html.md) for output behavior and [Policy](policy.md) before
changing URL rules.
