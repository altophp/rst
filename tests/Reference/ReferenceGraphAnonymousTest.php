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

use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Reference\ReferenceGraphBuilder;
use Alto\Rst\Reference\ReferenceStatus;
use Alto\Rst\Rst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReferenceGraph::class)]
#[CoversClass(ReferenceGraphBuilder::class)]
final class ReferenceGraphAnonymousTest extends TestCase
{
    public function testAnonymousReferencesPairWithTargetsInDocumentOrder(): void
    {
        $graph = self::graph(
            "`one`__ and `two`__.\n\n"
            . ".. __: https://one.test\n"
            . ".. __: https://two.test\n",
        );
        $references = $graph->references();

        self::assertSame('https://one.test', $references[0]->target?->destination);
        self::assertSame('https://two.test', $references[1]->target?->destination);
    }

    public function testCountMismatchMakesEveryAnonymousReferenceUnresolved(): void
    {
        $graph = self::graph(
            "`one`__ and `two`__.\n\n"
            . ".. __: https://one.test\n",
        );

        self::assertSame(
            [ReferenceStatus::Unresolved, ReferenceStatus::Unresolved],
            array_map(static fn($reference): ReferenceStatus => $reference->status, $graph->references()),
        );
        self::assertSame('reference/anonymous-mismatch', $graph->problems()->problems()[0]->code);
    }

    private static function graph(string $source): ReferenceGraph
    {
        return Rst::docutils()->parse($source)->references();
    }
}
