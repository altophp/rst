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
use Alto\Rst\Rst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReferenceGraph::class)]
#[CoversClass(ReferenceGraphBuilder::class)]
final class ReferenceGraphTableTest extends TestCase
{
    public function testGridTableLineSeparatorsAreNotParsedAsSubstitutionMarkup(): void
    {
        $source = "+-----+-----+\n"
            . "| A   | B   |\n"
            . "+=====+=====+\n"
            . "| one | two |\n"
            . "| x   | y   |\n"
            . "+-----+-----+\n";

        self::assertSame([], Rst::docutils()->parse($source)->references()->problems()->problems());
    }

    public function testUnclosedSubstitutionInsideGridTableCellIsStillReported(): void
    {
        $source = "+----------+-----+\n"
            . "| A        | B   |\n"
            . "+==========+=====+\n"
            . "| x        | two |\n"
            . "| |missing | y   |\n"
            . "+----------+-----+\n";
        $problems = Rst::docutils()->parse($source)->references()->problems()->problems();

        self::assertCount(1, $problems);
        self::assertSame('inline/unmatched-start-string', $problems[0]->code);
        self::assertNotNull($problems[0]->span);
        self::assertSame(strpos($source, '|missing'), $problems[0]->span->start);
    }

    public function testReferenceMarkupNeverMatchesAcrossTableCellLineSegments(): void
    {
        $source = "========  ==========\n"
            . "col       text\n"
            . "========  ==========\n"
            . "first     `target\n"
            . "          link`_\n"
            . "========  ==========\n";

        self::assertSame([], Rst::docutils()->parse($source)->references()->references());
    }

    public function testCompleteReferencesInsideSegmentsKeepTheirOriginalSpans(): void
    {
        $source = "========  ==========\n"
            . "col       text\n"
            . "========  ==========\n"
            . "first     one_\n"
            . "          two_\n"
            . "========  ==========\n";
        $references = Rst::docutils()->parse($source)->references()->references();

        self::assertCount(2, $references);
        self::assertSame(strpos($source, 'one_'), $references[0]->span->start);
        self::assertSame(strpos($source, 'two_'), $references[1]->span->start);
    }

    public function testGridTableReferencesKeepTheirPhysicalSourceSpans(): void
    {
        $source = "+--------+--------+\n"
            . "| A      | B      |\n"
            . "+========+========+\n"
            . "| same_  | same_  |\n"
            . "| same_  | same_  |\n"
            . "+--------+--------+\n";
        $references = Rst::docutils()->parse($source)->references()->references();
        $offsets = [];
        $offset = 0;

        while (false !== ($offset = strpos($source, 'same_', $offset))) {
            $offsets[] = $offset;
            $offset += \strlen('same_');
        }

        self::assertCount(4, $references);
        self::assertSame(
            [$offsets[0], $offsets[2], $offsets[1], $offsets[3]],
            array_map(static fn($reference): int => $reference->span->start, $references),
        );
    }
}
