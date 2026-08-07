# Extensions

Extensions add dialect capabilities and executable behavior to an immutable
profile. The Symfony profile is assembled from `SymfonyExtension`, which
keeps `configuration-block` and PHP symbol roles outside generic parser and
renderer branches.

## Define an extension

Extend `AbstractExtension` and override only the contracts the extension
needs:

```php
use Alto\Rst\Extension\AbstractExtension;
use Alto\Rst\Node\DirectiveBodyKind;
use Alto\Rst\Profile\DirectiveSpec;
use Alto\Rst\Profile\RoleSpec;

final readonly class AcmeExtension extends AbstractExtension
{
    public function name(): string
    {
        return 'acme';
    }

    public function directives(): array
    {
        return [
            new DirectiveSpec(
                'panel',
                hasArgument: false,
                bodyKind: DirectiveBodyKind::Blocks,
            ),
        ];
    }

    public function roles(): array
    {
        return [new RoleSpec('ticket')];
    }
}
```

Compile it into an existing profile:

```php
use Alto\Rst\Profile\Profile;

$profile = Profile::sphinx()->withExtension(new AcmeExtension());
```

The returned profile is a new value. The original is unchanged. Later
extensions replace behavior with the same normalized handler, pass, rule, or
provider name.

## Declare directive body semantics

`DirectiveSpec::bodyKind` tells the parser how to represent content:

| Kind | Meaning |
| --- | --- |
| `DirectiveBodyKind::Blocks` | Parse ordinary RST blocks and expose them through `Directive::children()`. |
| `DirectiveBodyKind::Literal` | Preserve source bytes without interpreting code or data as RST. |
| `DirectiveBodyKind::Opaque` | Preserve syntax the ROM cannot safely interpret and mark reference coverage incomplete. |
| `DirectiveBodyKind::None` | The directive accepts no body. |

`Blocks` is the default when `hasBody` is true. A parsed block child keeps its
original document byte span. `Directive::rawBody` remains available for exact
source preservation in every non-empty case.

Choose `Opaque` only when the extension owns a syntax that the ROM cannot
represent. Declaring code or data as `Blocks` can create false references;
declaring ordinary RST as `Opaque` prevents the graph from proving that its
coverage is complete.

## Available contracts

An extension can contribute:

| Method | Contract | Execution point |
| --- | --- | --- |
| `directives()` | `DirectiveSpec` | Parsing and profile capability checks |
| `roles()` | `RoleSpec` | Inline parsing and profile capability checks |
| `directiveHandlers()` | `DirectiveHandler` | HTML rendering and Markdown conversion |
| `roleHandlers()` | `RoleHandler` | HTML rendering and Markdown conversion |
| `lintRules()` | `DocumentRule`, `SourceRule`, or `ContextRule` | `Linter::lint()` when the profile is passed |
| `fixPasses()` | `FixPass` | `FixEngine::fix()` |
| `formatterPasses()` | `FormatterPass` | `Formatter::format()` |
| `statisticsProviders()` | `StatisticsProvider` | `ExtensionStatistics::collect()` |

Directive and role Markdown handlers return `ConversionResult`. They use the
normal conversion report for unsupported, lossy, and approximated behavior.

Directive handlers also receive:

- `DirectiveRenderContext`, which renders structured body children through
  the active parent renderer;
- `DirectiveConversionContext`, which converts the body through the active
  parent writer.

These contexts retain local targets, project references, document paths,
include stacks, conversion options, and extension behavior. A handler should
not create an isolated renderer or converter for a structured body.

Fix and formatter passes return byte-accurate `SourcePatch` values over the
original source. Conflicting patches fail visibly. Formatter passes also go
through the structural parse guard; a pass that changes the document tree is
skipped and counted in `FormatResult::skippedExtensionPasses`.

Statistics are namespaced by provider:

```php
use Alto\Rst\Extension\ExtensionStatistics;

$groups = new ExtensionStatistics()->collect(
    $parsed->document(),
    $source,
    $profile,
);
```

## Run extension lint rules

Pass the active profile as the fifth linter argument:

```php
$report = new Linter()->lint(
    $parsed->document(),
    LintConfig::recommended(),
    $source,
    $parsed->references(),
    $profile,
);
```

Rules contributed by extensions replace configured rules with the same code.
This keeps the final rule set deterministic.

## Safety boundaries

A directive handler runs only when the profile also enables its directive.
Registering a handler for `include`, `raw`, or `literalinclude` cannot bypass
the deny list.

`FileAccessPolicy` is the only authority for include reads. Raw HTML has a
separate conversion option and remains disabled by default. Extension
handlers receive those policies but cannot activate them.

Extensions are trusted PHP code. HTML handlers must escape text and enforce
the received `HtmlPolicy`; source passes must return patches only inside the
provided source. Do not load untrusted extension implementations.

## Symfony configuration blocks

`ConfigurationBlockHandler`:

- consumes the body nodes already parsed with the Symfony profile;
- retains a raw-source fallback for documents parsed under a narrower profile;
- renders HTML inside a `configuration-block` container;
- converts nested code blocks through the parent conversion context and keeps
  project references and include state;
- reanchors nested conversion issues on the outer directive span;
- records the loss of tabbed presentation as an approximation.

`ScreencastHandler` owns the Symfony-only `screencast` directive. It renders
a semantic HTML aside and converts the same structured body to a GitHub tip
without a profile-name branch in the generic renderer or writer.

The same public contracts can express custom roles, lint rules, fixes,
formatting passes, and statistics without adding dialect switches to core.

## Symfony PHP symbol roles

`PhpSymbolRoleHandler` owns `class`, `method`, `phpclass`, `phpmethod`, and
`phpfunction` for `Profile::symfony()`. Simple symbols render as escaped HTML
code and Markdown code spans without an approximation. The handler preserves
the visible title in `Title <Target>` forms and records the discarded target
as a lossy Markdown conversion issue.

The Sphinx profile does not install these handlers. It keeps its generic
role behavior. The Symfony `namespace` role currently uses the same generic
fallback.
