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

Continue with:

- [HTML](rendering/html.md) for output behavior and safety guarantees.
- [Policy](rendering/policy.md) before changing URL rules.

## Public contract

`HtmlRenderer::render()` accepts a `Document`, matching `Source`, optional
`RenderOptions`, and an optional prebuilt `ReferenceGraph`.

```php
$html = $renderer->render(
    $parsed->document(),
    $source,
    new RenderOptions(profile: $profile),
    $parsed->references(),
);
```

`RenderOptions` contains `htmlPolicy` and an optional profile. Without a
profile, only base directive behavior is available.

`HtmlPolicy::safe()` allows HTTP, HTTPS, mail, telephone, relative, and
fragment destinations. `withAllowedSchemes()` returns a policy with a complete
replacement allowlist. `allowedSchemes()` and `isUrlAllowed()` expose the
effective rule.
