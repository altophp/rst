# Installation

Install ALTO RST in a PHP 8.4 or newer application, then render one document to
verify the Composer autoloader and runtime.

## Install the package

```bash
composer require alto/rst
```

Core has no runtime Composer dependency. Python, docutils, and Sphinx are not
used at runtime.

## Verify the installation

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Alto\Rst\Rst;

echo Rst::docutils()->toHtml("Ready\n=====\n");
```

The command must print:

```html
<section id="ready">
<h1>Ready</h1>
</section>
```

If Composer cannot resolve the package, verify the PHP version reported by
`php -v` and the platform requirements reported by `composer check-platform-reqs`.

Continue with [Getting started](getting-started.md) to select a profile and
reuse the parsed result.
