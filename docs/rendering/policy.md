# Policy

`HtmlPolicy` controls which URL schemes may appear in rendered links and
images. The safe policy allows `http`, `https`, `mailto`, and `tel`, plus
relative references and fragments.

```php
use Alto\Rst\Render\HtmlPolicy;
use Alto\Rst\Render\RenderOptions;

$policy = HtmlPolicy::safe()->withAllowedSchemes('https', 'mailto');
$options = new RenderOptions(htmlPolicy: $policy);

$html = $rst->toHtml($source, $options);
```

`withAllowedSchemes()` replaces the complete allowlist and returns a new
policy. A disallowed destination keeps its element and visible text but
receives an empty URL.

Literal and percent-encoded control bytes are removed before scheme detection,
matching browser URL interpretation. Scheme-relative and path-relative URLs
have no scheme and remain allowed.

The HTML renderer has no raw passthrough mode and performs no filesystem or
network access. Conversion has separate authority controls described in
[Security](../security.md).
