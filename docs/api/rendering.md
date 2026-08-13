# Rendering API

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

The renderer escapes text and has no raw passthrough or file-reading path. See
[HTML](../rendering/html.md) and [Policy](../rendering/policy.md).
