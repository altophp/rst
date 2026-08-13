# Custom rules

Implement `DocumentRule` for tree-only checks, `SourceRule` for checks that
need exact bytes, or `ContextRule` for checks that need the reference graph.

This document rule requires an Installation section:

```php
use Alto\Rst\Lint\DocumentRule;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\Section;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;

final readonly class InstallationSectionRule implements DocumentRule
{
    public function code(): string
    {
        return 'acme/installation-section';
    }

    public function check(
        Document $document,
        ProblemCollector $problems,
    ): void {
        foreach ($document->descendants() as $node) {
            if ($node instanceof Section
                && 'Installation' === $node->title->text->text
            ) {
                return;
            }
        }

        $problems->add(new Problem(
            ProblemSeverity::Warning,
            $this->code(),
            'The document has no Installation section.',
            $document->span(),
        ));
    }
}
```

Register it with `LintConfig::withRule()`. The rule must report findings through
the collector, use a stable namespaced code, and never mutate the document.
An extension can contribute the same rule through its `lintRules()` contract.
