# Install Alto Rst

Alto Rst requires PHP 8.4 or later and Composer. Core has no runtime Composer
dependencies.

## Install

```bash
composer require alto/rst
```

The package uses PSR-4 autoloading, so Composer's autoloader is all you need:

```php
require __DIR__.'/vendor/autoload.php';
```

## Verify a checkout

From a clone of the repository, install the development dependencies and run
the complete project gate:

```bash
composer install
composer qa
```

The command runs PHPStan at maximum strictness, PHP CS Fixer, and PHPUnit.

Coverage is a separate command and fails below the 97 percent line floor:

```bash
composer coverage
```

The suite runs against a pinned docutils 0.23 fixture corpus committed to the
repository. docutils is a development oracle, not a runtime dependency.

## Platform contract

- PHP 8.4 or later
- Composer for installation and development
- no Python or Sphinx process at runtime
- no runtime Composer dependency in core

Continue with [Parse and render a document](parse-and-render.md).
