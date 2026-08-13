# HTML

The HTML renderer emits semantic sections, paragraphs, lists, tables, code,
notes, references, and profile-enabled directives.

```php
use Alto\Rst\Rst;

$source = <<<'RST'
Guide
=====

Read the `project site <https://example.com>`_.
RST;

$html = Rst::sphinx()->toHtml($source);
```

Text and attributes are escaped. Hyperlinks resolve through the reference
graph and every destination passes through `HtmlPolicy`. Unknown constructs
degrade to inert output instead of raw HTML.

Directive handlers run only when the active profile enables their directive.
A structured directive body is rendered through the parent renderer, keeping
the same profile, references, and URL policy.

Rendering never reads a file. Include expansion is available only to explicit
conversion workflows with a `FileAccessPolicy`. See [Policy](policy.md) and
[Security](../security.md) for the complete authority boundary.
