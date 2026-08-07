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
use Alto\Rst\Reference\ReferenceDefinition;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Reference\ReferenceGraphBuilder;
use Alto\Rst\Reference\ReferenceStatus;
use Alto\Rst\Reference\ReferenceType;
use Alto\Rst\Rst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReferenceGraph::class)]
#[CoversClass(ReferenceGraphBuilder::class)]
final class ReferenceGraphHyperlinkTest extends TestCase
{
    public function testNamedReferenceResolvesCaseAndWhitespaceNormalizedTarget(): void
    {
        $graph = self::graph("See `A   NAME`_.\n\n.. _a name: https://example.com\n");
        $reference = $graph->references()[0];

        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertInstanceOf(ReferenceDefinition::class, $reference->target);
        self::assertSame('https://example.com', $reference->target->destination);
        self::assertSame(DefinitionKind::Hyperlink, $reference->target->kind);
        self::assertSame([$reference], $graph->incoming($reference->target));
        self::assertFalse($graph->problems()->hasProblems());
    }

    public function testIndirectChainResolvesWithoutAnArbitraryDepthLimit(): void
    {
        $targets = '';

        for ($index = 1; $index <= 12; ++$index) {
            $next = 12 === $index ? 'https://example.com/final' : 'target'.($index + 1).'_';
            $targets .= sprintf(".. _target%d: %s\n", $index, $next);
        }

        $reference = self::graph("See target1_.\n\n".$targets)->references()[0];

        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertSame('https://example.com/final', $reference->target?->destination);
    }

    public function testIndirectCycleIsTypedAndReported(): void
    {
        $graph = self::graph("See a_.\n\n.. _a: b_\n.. _b: a_\n");
        $reference = $graph->references()[0];

        self::assertSame(ReferenceStatus::Circular, $reference->status);
        self::assertNull($reference->target);
        self::assertSame(
            ['reference/circular-indirect-target', 'reference/circular-indirect-target', 'reference/circular-target'],
            array_map(static fn ($problem): string => $problem->code, $graph->problems()->problems()),
        );
    }

    public function testDuplicateExplicitTargetsAreAmbiguous(): void
    {
        $graph = self::graph("See a_.\n\n.. _a: https://one.test\n.. _a: https://two.test\n");
        $reference = $graph->references()[0];

        self::assertSame(ReferenceStatus::Ambiguous, $reference->status);
        self::assertNull($graph->target('a'));
        self::assertSame(
            ['reference/duplicate-explicit-target', 'reference/ambiguous-target'],
            array_map(static fn ($problem): string => $problem->code, $graph->problems()->problems()),
        );
    }

    public function testInlineTargetParticipatesInForwardResolution(): void
    {
        $reference = self::graph("See `point here`_ first.\n\nThen _`point here` defines it.\n")->references()[0];

        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertSame(DefinitionKind::InlineTarget, $reference->target?->kind);
    }

    public function testReferenceListsCanBeFilteredByType(): void
    {
        $graph = self::graph("See target_.\n\n.. _target: https://example.com\n");

        self::assertCount(1, $graph->references(ReferenceType::Hyperlink));
        self::assertSame([], $graph->references(ReferenceType::Citation));
    }

    public function testMissingIndirectTargetIsReportedWithoutAnOccurrence(): void
    {
        $graph = self::graph(".. _a: missing_\n");

        self::assertSame('reference/unresolved-indirect-target', $graph->problems()->problems()[0]->code);
    }

    public function testQuotedIndirectTargetMayContainAColon(): void
    {
        $reference = self::graph(
            "See a_.\n\n"
            .".. _a: `RFC: 1`_\n"
            .".. _`RFC: 1`: https://example.com/final\n",
        )->references()[0];

        self::assertSame('https://example.com/final', $reference->target?->destination);
    }

    private static function graph(string $source): ReferenceGraph
    {
        return Rst::sphinx()->parse($source)->references();
    }
}
