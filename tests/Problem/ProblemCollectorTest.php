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

namespace Alto\Rst\Tests\Problem;

use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemReport;
use Alto\Rst\Problem\ProblemSeverity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProblemCollector::class)]
final class ProblemCollectorTest extends TestCase
{
    public function testStartsEmpty(): void
    {
        $collector = new ProblemCollector();

        self::assertCount(0, $collector);
        self::assertFalse($collector->report()->hasProblems());
    }

    public function testCountsAddedProblems(): void
    {
        $collector = new ProblemCollector();
        $collector->add(new Problem(ProblemSeverity::Info, 'document/empty', 'Document is empty.'));
        $collector->add(new Problem(ProblemSeverity::Error, 'table/malformed', 'Malformed table.'));

        self::assertCount(2, $collector);
    }

    public function testReportPreservesInsertionOrder(): void
    {
        $first = new Problem(ProblemSeverity::Warning, 'section/short-adornment', 'Underline too short.');
        $second = new Problem(ProblemSeverity::Error, 'table/malformed', 'Malformed table.');

        $collector = new ProblemCollector();
        $collector->add($first);
        $collector->add($second);

        self::assertSame([$first, $second], iterator_to_array($collector->report()));
    }

    public function testReportIsASnapshot(): void
    {
        $collector = new ProblemCollector();
        $collector->add(new Problem(ProblemSeverity::Info, 'document/empty', 'Document is empty.'));

        $report = $collector->report();
        self::assertInstanceOf(ProblemReport::class, $report);
        self::assertCount(1, $report);

        $collector->add(new Problem(ProblemSeverity::Error, 'table/malformed', 'Malformed table.'));

        self::assertCount(1, $report);
        self::assertCount(2, $collector->report());
    }
}
