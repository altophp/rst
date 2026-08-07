# Lint RST

Alto Rst reports source, document-structure, reference, and profile-specific
problems through one ordered `ProblemReport`. Linting never changes the
source.

## Run the recommended rules

Parse the exact bytes that you pass to the linter:

```php
use Alto\Rst\Lint\LintConfig;
use Alto\Rst\Lint\Linter;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;

$source = Source::fromString($input);
$profile = Profile::symfony();
$parsed = Rst::symfony()->parse($source->bytes);

$problems = new Linter()->lint(
    $parsed->document(),
    LintConfig::recommended(),
    $source,
    $parsed->references(),
    $profile,
);
```

Passing the original `Source` enables source rules. Passing the existing
reference graph reuses its inline-analysis cache. Passing the active profile
runs lint rules contributed by its extensions.

## Inspect findings

Every finding has a stable code, severity, message, and original byte span:

```php
foreach ($problems as $problem) {
    echo $problem->severity->value;
    echo ' '.$problem->code;
    echo ' '.$problem->message;
}
```

Parser recovery problems and reference-resolution problems are separate from
lint findings:

```php
$parserProblems = $parsed->problems();
$referenceProblems = $parsed->references()->problems();
```

Keep these three channels separate when presenting diagnostics. A lint
finding describes a rule violation. A parser problem means Alto recovered
from malformed RST. A reference problem means a target or reference could
not be resolved safely.

## Recommended rule set

`LintConfig::recommended()` enables 20 rules:

| Rule code | Checks |
| --- | --- |
| `lint/no-tab` | Tabs are not used in source. |
| `lint/indentation` | Relevant indentation is a multiple of four by default. |
| `lint/max-line-length` | Lines contain at most 80 characters by default. |
| `lint/trailing-whitespace` | Lines do not end with whitespace. |
| `lint/max-blank-lines` | Blank-line runs contain at most two lines by default. |
| `lint/section-level-jump` | Section nesting does not skip a level. |
| `lint/empty-section` | Every section has body content. |
| `lint/transition-placement` | Transitions do not open or close a document and are not adjacent. |
| `lint/forbidden-directive` | Forbidden directives are not used. The default Symfony-oriented policy rejects `index` and `caution`. |
| `lint/code-block-language` | Code directives declare a recognized language. |
| `lint/code-block-terminal` | Console examples use `terminal` instead of `bash`, `sh`, `shell`, or `console`. |
| `lint/version-directive-version` | Version directives carry a numeric version such as `7.1`. |
| `lint/blank-line-after-directive` | A blank line separates a directive head from its body. |
| `lint/duplicate-target` | Normalized hyperlink target names are unique. |
| `lint/unresolved-reference` | Local references are resolved, unambiguous, and acyclic. Deferred Sphinx references wait for the project map. |
| `lint/unused-external-link-definition` | External link definitions are used when reference coverage is complete. |
| `lint/blank-line-after-anchor` | A blank line follows an explicit internal anchor. |
| `lint/forbidden-link-destination` | Link schemes satisfy the active `HtmlPolicy`. |
| `lint/invalid-link-destination` | URL syntax is locally valid without network access. |
| `lint/american-english` | The configured source vocabulary uses the built-in American English spellings. |

The rules operate at different levels. Document rules need only the parsed
tree. Source and context rules also need the original `Source`. Reference
rules use the graph.

## Configure rules

`LintConfig` is immutable. Disable a rule by code:

```php
$config = LintConfig::recommended()
    ->withoutRule('lint/american-english');
```

Add or replace a rule with `withRule()`:

```php
$config = $config->withRule($customRule);
```

A rule with an existing code replaces that rule in place. Unknown codes
passed to `withoutRule()` are ignored.

Rules with policy values can be replaced explicitly:

```php
use Alto\Rst\Lint\Rule\IndentationRule;
use Alto\Rst\Lint\Rule\MaxLineLengthRule;

$config = LintConfig::recommended()
    ->withRule(new IndentationRule(2))
    ->withRule(new MaxLineLengthRule(100));
```

## Reference coverage

The reference graph analyzes normal text, table-cell segments, and structured
directive bodies recursively. Code and literal data are skipped according to
their declared body kind. Unknown or explicitly opaque directive syntax keeps
coverage incomplete.

```php
if (!$parsed->references()->isCoverageComplete()) {
    // Do not conclude that an unreferenced definition is unused.
}
```

The recommended unused-definition rule performs this check and declines to
report when coverage is incomplete.

## Apply related fixes

Linting only reports. `FixEngine` can safely address selected whitespace and
separator findings, while `Formatter` handles canonical layout. Both return
in-memory patches and never write files.

Continue with [Fix, format, and edit](writing.md).
