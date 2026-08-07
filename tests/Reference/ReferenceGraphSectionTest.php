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

use Alto\Rst\Reference\DefinitionKind;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Reference\ReferenceGraphBuilder;
use Alto\Rst\Reference\ReferenceStatus;
use Alto\Rst\Rst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReferenceGraph::class)]
#[CoversClass(ReferenceGraphBuilder::class)]
final class ReferenceGraphSectionTest extends TestCase
{
    public function testSectionTitleIsAnImplicitTarget(): void
    {
        $reference = self::graph("See `The Guide`_.\n\nThe Guide\n=========\n")->references()[0];

        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertSame(DefinitionKind::Section, $reference->target?->kind);
    }

    public function testInlineMarkupIsRemovedFromTheImplicitSectionReferenceName(): void
    {
        $source = "See `widget`_.\n\n``widget``\n==========\n";
        $graph = self::graph($source);
        $reference = $graph->references()[0];
        $target = $reference->target;

        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertNotNull($target);
        self::assertSame(DefinitionKind::Section, $target->kind);
        self::assertSame('widget', $target->name);
        self::assertSame('widget', $target->normalizedName);
        self::assertSame(strpos($source, '``widget``'), $target->span->start);
        self::assertSame(\strlen('``widget``'), $target->span->length);
        self::assertSame('widget', $graph->targetTitle($target));
    }

    public function testNestedInlineMarkupProducesTheVisibleSectionReferenceName(): void
    {
        $reference = self::graph(
            "See `A great guide`_.\n\nA *great* **guide**\n===================\n",
        )->references()[0];

        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertSame('A great guide', $reference->target?->name);
    }

    public function testDuplicateImplicitSectionTitlesAreAmbiguous(): void
    {
        $reference = self::graph("See Same_.\n\nSame\n====\n\nSame\n====\n")->references()[0];

        self::assertSame(ReferenceStatus::Ambiguous, $reference->status);
    }

    public function testExplicitTargetOverridesDuplicateImplicitSectionTitles(): void
    {
        $source = "See Same_.\n\nSame\n====\n\nSame\n====\n\n.. _same: https://example.com\n";
        $reference = self::graph($source)->references()[0];

        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertSame('https://example.com', $reference->target?->destination);
    }

    public function testEmptyTargetImmediatelyBeforeSectionAdoptsTheSection(): void
    {
        $graph = self::graph("See install_.\n\n.. _install:\n\nInstallation\n============\n");
        $reference = $graph->references()[0];

        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertSame(DefinitionKind::Hyperlink, $reference->target?->kind);
        self::assertSame('Installation', $graph->targetTitle($reference->target));
        self::assertTrue(\in_array($reference->target, $graph->definitions(), true));
        self::assertSame([$reference], $graph->incoming($reference->target));
        self::assertFalse(\in_array($reference->target, $graph->unusedDefinitions(), true));
    }

    public function testSphinxRefIsDeferredWhenNoLocalTargetExists(): void
    {
        $reference = self::graph("See :ref:`other-file-label`.\n")->references()[0];

        self::assertSame(ReferenceStatus::Deferred, $reference->status);
        self::assertFalse(self::graph("See :ref:`other-file-label`.\n")->problems()->hasProblems());
    }

    public function testSphinxRefKeepsExplicitTitleAndResolvesLocalTarget(): void
    {
        $graph = self::graph("See :ref:`The guide <install>`.\n\n.. _install:\n\nInstallation\n============\n");
        $reference = $graph->references()[0];

        self::assertSame('The guide', $reference->explicitTitle);
        self::assertSame('install', $reference->label);
        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertSame(DefinitionKind::Hyperlink, $reference->target?->kind);
        self::assertSame('Installation', $graph->targetTitle($reference->target));
    }

    private static function graph(string $source): ReferenceGraph
    {
        return Rst::sphinx()->parse($source)->references();
    }
}
