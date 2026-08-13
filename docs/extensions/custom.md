# Custom extension

This extension contributes one namespaced statistics provider and no syntax or
rendering behavior.

```php
use Alto\Rst\Extension\AbstractExtension;
use Alto\Rst\Extension\ExtensionStatistics;
use Alto\Rst\Extension\StatisticsProvider;
use Alto\Rst\Node\Document;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Source\Source;

final readonly class AcmeExtension extends AbstractExtension
{
    public function name(): string
    {
        return 'acme';
    }

    public function statisticsProviders(): array
    {
        return [new NodeCount()];
    }
}

final readonly class NodeCount implements StatisticsProvider
{
    public function name(): string
    {
        return 'nodes';
    }

    public function collect(
        Document $document,
        Source $source,
        Profile $profile,
    ): array {
        return ['count' => iterator_count($document->descendants())];
    }
}

$profile = Profile::sphinx()->withExtension(new AcmeExtension());
$groups = new ExtensionStatistics()->collect(
    $parsed->document(),
    $source,
    $profile,
);
```

Provider names become stable keys inside the extension statistics group. A
provider returns only scalar values or `null` and receives immutable document,
source, and profile inputs.

Use a different contribution method only when the extension owns that
behavior. See [Extension points](extension-points.md) for the complete map.
