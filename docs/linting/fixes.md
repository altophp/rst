# Fixes

`FixEngine` performs conservative source cleanup and returns changed bytes plus
the exact patches. It never writes a file.

```php
use Alto\Rst\Fix\FixEngine;
use Alto\Rst\Fix\FixOptions;
use Alto\Rst\Profile\Profile;

$result = new FixEngine()->fix(
    $input,
    new FixOptions(
        removeTrailingWhitespace: true,
        maxBlankLines: 2,
        blankLineAfterAnchor: true,
        blankLineBeforeDirectiveBody: true,
        normalizeDefaultRoleAsLiteral: false,
    ),
    Profile::symfony(),
);

$fixed = $result->bytes;
$patches = $result->patches;
```

Fixes remove trailing whitespace and excess blank lines, and can insert safe
separators after anchors or before directive bodies. Protected literal blocks,
comments, tables, and directive content are not rewritten blindly.

Default-role normalization is disabled because changing single-backtick text
to an inline literal is a policy decision. Enable it only when the project
defines that convention.

The result is reparsed. Inspect its parse result and patches before persistence.
Use [Files](../editing/files.md) when the corrected bytes must replace a file.
