# Documents

The parsed document is a typed tree. Containers expose `children()` and the
document exposes `descendants()` for source-order traversal.

```php
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\DirectiveBodyKind;

$document = $result->document();

foreach ($document->descendants() as $node) {
    if ($node instanceof Directive
        && DirectiveBodyKind::Blocks === $node->bodyKind
    ) {
        $bodyNodes = $node->children();
    }
}
```

Directive nodes always retain their raw body span. A profile can additionally
declare one of four body models:

- `Blocks` parses ordinary RST children;
- `Literal` preserves code or data without interpreting references;
- `Opaque` preserves extension syntax and marks graph coverage incomplete;
- `None` declares that the directive accepts no body.

Nodes are immutable views over the parsed source. Use the typed
[Editing](../editing.md) API to plan changes rather than mutating the tree.
See [Positions](positions.md) for byte-span semantics.
