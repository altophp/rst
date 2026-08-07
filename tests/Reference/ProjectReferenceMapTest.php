<?php

declare(strict_types=1);

/*
 * This file is part of the ALTO library.
 *
 * © 2026-present Simon André
 *
 * For full copyright and license information, please see
 * the LICENSE file distributed with this source code.
 */

namespace Alto\Rst\Tests\Reference;

use Alto\Rst\Reference\ProjectReferenceMap;
use Alto\Rst\Reference\ProjectReferenceResolution;
use Alto\Rst\Reference\ReferenceStatus;
use Alto\Rst\Reference\ReferenceType;
use Alto\Rst\Rst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectReferenceMap::class)]
#[CoversClass(ProjectReferenceResolution::class)]
final class ProjectReferenceMapTest extends TestCase
{
    public function testRefResolvesAcrossDocumentsWithItsTargetTitle(): void
    {
        $map = self::map([
            'guide/start.rst' => "See :ref:`install-label`.\n",
            'install.rst' => ".. _install-label:\n\nInstallation\n============\n",
        ]);
        $reference = self::reference($map, 'guide/start', ReferenceType::SphinxRef);
        $resolution = $map->resolution('guide/start.rst', $reference);

        self::assertInstanceOf(ProjectReferenceResolution::class, $resolution);
        self::assertSame(ReferenceStatus::Resolved, $resolution->status);
        self::assertSame('install', $resolution->targetPath);
        self::assertSame('Installation', $resolution->displayLabel);
    }

    public function testExplicitRefTitleWinsAcrossDocuments(): void
    {
        $map = self::map([
            'start.rst' => "See :ref:`Read this <install-label>`.\n",
            'install.rst' => ".. _install-label:\n\nInstallation\n============\n",
        ]);
        $reference = self::reference($map, 'start', ReferenceType::SphinxRef);

        self::assertSame('Read this', $map->resolution('start', $reference)?->displayLabel);
    }

    public function testResolvedRefTitleFlattensInlineMarkup(): void
    {
        $map = self::map([
            'start.rst' => "See :ref:`label`.\n",
            'target.rst' => ".. _label:\n\nA *great* title\n===============\n",
        ]);

        self::assertSame('A great title', $map->outgoing('start')[0]->displayLabel);
    }

    public function testDocResolvesRelativeAndRootAbsolutePathsWithoutAnExtension(): void
    {
        $map = self::map([
            'guide/start.rst' => "See :doc:`other` and :doc:`Root </root>`.\n",
            'guide/other.rst' => "Other\n=====\n",
            'root.rst' => "Root\n====\n",
        ]);
        $references = $map->outgoing('guide/start.rst');

        self::assertSame('guide/other', $references[0]->targetPath);
        self::assertSame('Other', $references[0]->displayLabel);
        self::assertSame('root', $references[1]->targetPath);
        self::assertSame('Root', $references[1]->displayLabel);
    }

    public function testDirectoryPathResolvesItsIndexDocument(): void
    {
        $map = self::map([
            'start.rst' => "See :doc:`guide`.\n",
            'guide/index.rst' => "Guide\n=====\n",
        ]);

        self::assertSame('guide/index', $map->outgoing('start')[0]->targetPath);
    }

    public function testDuplicateLabelsAcrossDocumentsAreAmbiguous(): void
    {
        $map = self::map([
            'start.rst' => "See :ref:`shared`.\n",
            'one.rst' => ".. _shared:\n\nOne\n===\n",
            'two.rst' => ".. _shared:\n\nTwo\n===\n",
        ]);
        $resolution = $map->outgoing('start')[0];

        self::assertSame(ReferenceStatus::Ambiguous, $resolution->status);
        self::assertSame('reference/ambiguous-project-target', $map->problems()->problems()[0]->code);
    }

    public function testDuplicateLabelIncludingTheSourceDocumentIsAmbiguous(): void
    {
        $map = self::map([
            'start.rst' => ".. _shared:\n\nStart\n=====\n\nSee :ref:`shared`.\n",
            'other.rst' => ".. _shared:\n\nOther\n=====\n",
        ]);

        self::assertSame(ReferenceStatus::Ambiguous, $map->outgoing('start')[0]->status);
    }

    public function testDuplicateLabelsInsideOneDocumentRemainAmbiguous(): void
    {
        $map = self::map([
            'start.rst' => "See :ref:`shared`.\n\n.. _shared:\n.. _shared:\n\nTarget\n======\n",
        ]);

        self::assertSame(ReferenceStatus::Ambiguous, $map->outgoing('start')[0]->status);
    }

    public function testImplicitSectionTitlesAreNotProjectRefLabels(): void
    {
        $map = self::map([
            'start.rst' => "See :ref:`Installation`.\n",
            'install.rst' => "Installation\n============\n",
        ]);

        self::assertSame(ReferenceStatus::Unresolved, $map->outgoing('start')[0]->status);
    }

    public function testImplicitSectionLabelsCanBeEnabledForSymfonyProjects(): void
    {
        $source = Rst::sphinx()->parse("See :ref:`serializer-normalizers`.\n");
        $target = Rst::sphinx()->parse("Serializer Normalizers\n======================\n");
        $map = new ProjectReferenceMap([
            'start.rst' => $source->references(),
            'serializer.rst' => $target->references(),
        ], true);

        $resolution = $map->outgoing('start')[0];

        self::assertSame(ReferenceStatus::Resolved, $resolution->status);
        self::assertSame('serializer', $resolution->targetPath);
        self::assertSame('Serializer Normalizers', $resolution->displayLabel);
    }

    public function testImplicitSectionLabelsPreferTheSourceDocument(): void
    {
        $source = Rst::sphinx()->parse(
            "See :ref:`locales`.\n\n``locales``\n===========\n",
        );
        $other = Rst::sphinx()->parse("Locales\n=======\n");
        $map = new ProjectReferenceMap([
            'constraint.rst' => $source->references(),
            'intl.rst' => $other->references(),
        ], true);

        self::assertSame('constraint', $map->outgoing('constraint')[0]->targetPath);
    }

    public function testImplicitSectionLabelsFallBackToMatchingDocumentNames(): void
    {
        $source = Rst::sphinx()->parse("See :ref:`Webhook <webhook>` and :ref:`Logs </logging>`.\n");
        $webhook = Rst::sphinx()->parse("Webhook\n=======\n");
        $logging = Rst::sphinx()->parse("Logging\n=======\n");
        $map = new ProjectReferenceMap([
            'reference/attributes.rst' => $source->references(),
            'webhook.rst' => $webhook->references(),
            'logging.rst' => $logging->references(),
        ], true);

        self::assertSame('webhook', $map->outgoing('reference/attributes')[0]->targetPath);
        self::assertSame('logging', $map->outgoing('reference/attributes')[1]->targetPath);
    }

    public function testInputOrderDoesNotChangeProjectResolution(): void
    {
        $first = self::map([
            'start.rst' => "See :ref:`target`.\n",
            'target.rst' => ".. _target:\n\nTarget\n======\n",
        ]);
        $second = self::map([
            'target.rst' => ".. _target:\n\nTarget\n======\n",
            'start.rst' => "See :ref:`target`.\n",
        ]);

        self::assertSame(
            $first->outgoing('start')[0]->targetPath,
            $second->outgoing('start')[0]->targetPath,
        );
    }

    public function testIncomingAndOutgoingQueriesUseCanonicalPaths(): void
    {
        $map = self::map([
            'guide/start.rst' => "See :doc:`../target.rst`.\n",
            'target.rst' => "Target\n======\n",
        ]);

        self::assertCount(1, $map->outgoing('guide/start.rst'));
        self::assertCount(1, $map->incoming('/target.rst'));
    }

    public function testResolvesAReparsedFragmentReferenceByMeaning(): void
    {
        $map = self::map([
            'guide/start.rst' => "See :doc:`../target.rst`.\n",
            'target.rst' => "Target\n======\n",
        ]);
        $fragment = Rst::sphinx()->parse(":doc:`../target.rst`\n");
        $reference = $fragment->references()->references(ReferenceType::SphinxDoc)[0];

        $resolution = $map->resolveReference('guide/start.rst', $reference);

        self::assertInstanceOf(ProjectReferenceResolution::class, $resolution);
        self::assertSame(ReferenceStatus::Resolved, $resolution->status);
        self::assertSame('target', $resolution->targetPath);
        self::assertNull($map->resolveReference('missing.rst', $reference));
    }

    public function testExactDocnameWinsOverDirectoryIndexAlias(): void
    {
        $map = self::map([
            'start.rst' => "See :doc:`guide`.\n",
            'guide.rst' => "Exact\n=====\n",
            'guide/index.rst' => "Index\n=====\n",
        ]);

        self::assertSame('guide', $map->outgoing('start')[0]->targetPath);
        self::assertSame('Exact', $map->outgoing('start')[0]->displayLabel);
    }

    public function testMissingDocumentProducesAProjectProblem(): void
    {
        $map = self::map(['start.rst' => "See :doc:`missing`.\n"]);
        $resolution = $map->outgoing('start')[0];

        self::assertSame(ReferenceStatus::Unresolved, $resolution->status);
        self::assertSame('reference/unresolved-project-target', $map->problems()->problems()[0]->code);
    }

    public function testDocumentWithoutATitleFallsBackToItsDocname(): void
    {
        $map = self::map([
            'start.rst' => "See :doc:`plain`.\n",
            'plain.rst' => "Just a paragraph.\n",
        ]);

        self::assertSame('plain', $map->outgoing('start')[0]->displayLabel);
    }

    public function testUnknownQueriesAreEmptyAndOrdinaryReferencesAreIgnored(): void
    {
        $source = Rst::sphinx()->parse("See target_.\n\n.. _target: https://example.com/\n");
        $map = new ProjectReferenceMap(['./start.rst' => $source->references()]);
        $ordinary = $source->references()->references(ReferenceType::Hyperlink)[0];

        self::assertSame([], $map->outgoing('missing'));
        self::assertSame([], $map->incoming('missing'));
        self::assertNull($map->resolution('start', $ordinary));
        self::assertNull($map->resolveReference('start', $ordinary));
        self::assertFalse($map->problems()->hasProblems());
    }

    public function testDocumentCatalogHelpersResolveAliasesTitlesAndMissingPaths(): void
    {
        $map = self::map([
            'plain.rst' => "Just text.\n",
            'guide/index.rst' => "Guide\n=====\n",
            'target.rst' => "Target\n======\n",
        ]);

        self::assertSame(['guide/index', 'plain', 'target'], $map->documentPaths());
        self::assertSame('Guide', $map->documentTitleFor('/guide'));
        self::assertNull($map->documentTitleFor('plain.rst'));
        self::assertNull($map->documentTitleFor('missing'));
        self::assertSame('guide/index', $map->resolveDocumentPath('guide'));
        self::assertSame('target', $map->resolveDocumentPath('/target.rst'));
        self::assertNull($map->resolveDocumentPath('missing'));
    }

    /**
     * @param array<string, string> $sources
     */
    private static function map(array $sources): ProjectReferenceMap
    {
        $graphs = [];

        foreach ($sources as $path => $source) {
            $graphs[$path] = Rst::sphinx()->parse($source)->references();
        }

        return new ProjectReferenceMap($graphs);
    }

    private static function reference(
        ProjectReferenceMap $map,
        string $sourcePath,
        ReferenceType $type,
    ): \Alto\Rst\Reference\ReferenceOccurrence {
        foreach ($map->outgoing($sourcePath) as $resolution) {
            if ($type === $resolution->reference->type) {
                return $resolution->reference;
            }
        }

        self::fail('Expected project reference was not found.');
    }
}
