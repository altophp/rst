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
use Alto\Rst\Problem\ProblemReport;
use Alto\Rst\Problem\ProblemSeverity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProblemReport::class)]
final class ProblemReportTest extends TestCase
{
    public function testEmptyReport(): void
    {
        $report = new ProblemReport();

        self::assertCount(0, $report);
        self::assertFalse($report->hasProblems());
        self::assertNull($report->maxSeverity());
        self::assertFalse($report->hasAtLeast(ProblemSeverity::Info));
        self::assertSame([], iterator_to_array($report));
    }

    public function testIteratesProblemsInOrder(): void
    {
        $first = new Problem(ProblemSeverity::Info, 'document/empty', 'Document is empty.');
        $second = new Problem(ProblemSeverity::Error, 'table/malformed', 'Malformed table.');

        $report = new ProblemReport($first, $second);

        self::assertCount(2, $report);
        self::assertTrue($report->hasProblems());
        self::assertSame([$first, $second], iterator_to_array($report));
        self::assertSame([$first, $second], $report->problems());
    }

    public function testMaxSeverity(): void
    {
        $report = new ProblemReport(
            new Problem(ProblemSeverity::Warning, 'section/short-adornment', 'Underline too short.'),
            new Problem(ProblemSeverity::Severe, 'document/broken', 'Unreadable input.'),
            new Problem(ProblemSeverity::Info, 'document/empty', 'Document is empty.'),
        );

        self::assertSame(ProblemSeverity::Severe, $report->maxSeverity());
    }

    public function testHasAtLeast(): void
    {
        $report = new ProblemReport(
            new Problem(ProblemSeverity::Info, 'document/empty', 'Document is empty.'),
            new Problem(ProblemSeverity::Warning, 'section/short-adornment', 'Underline too short.'),
        );

        self::assertTrue($report->hasAtLeast(ProblemSeverity::Info));
        self::assertTrue($report->hasAtLeast(ProblemSeverity::Warning));
        self::assertFalse($report->hasAtLeast(ProblemSeverity::Error));
    }

    public function testFilterBySeverityKeepsEqualAndAbove(): void
    {
        $info = new Problem(ProblemSeverity::Info, 'document/empty', 'Document is empty.');
        $warning = new Problem(ProblemSeverity::Warning, 'section/short-adornment', 'Underline too short.');
        $error = new Problem(ProblemSeverity::Error, 'table/malformed', 'Malformed table.');

        $report = new ProblemReport($info, $warning, $error);
        $filtered = $report->filterBySeverity(ProblemSeverity::Warning);

        self::assertSame([$warning, $error], $filtered->problems());
        self::assertSame([$info, $warning, $error], $report->problems());
    }

    public function testFilterByAreaMatchesTheSegmentBeforeTheSlash(): void
    {
        $short = new Problem(ProblemSeverity::Warning, 'section/short-adornment', 'Underline too short.');
        $mismatch = new Problem(ProblemSeverity::Warning, 'section/adornment-mismatch', 'Overline differs.');
        $table = new Problem(ProblemSeverity::Error, 'table/malformed', 'Malformed table.');
        $lookalike = new Problem(ProblemSeverity::Error, 'sections/other', 'Not the section area.');

        $report = new ProblemReport($short, $mismatch, $table, $lookalike);
        $filtered = $report->filterByArea('section');

        self::assertSame([$short, $mismatch], $filtered->problems());
    }

    public function testFilterByAreaMatchesSlashlessCodesWhole(): void
    {
        $slashless = new Problem(ProblemSeverity::Info, 'document', 'Whole-code area.');
        $other = new Problem(ProblemSeverity::Info, 'document/empty', 'Document is empty.');

        $report = new ProblemReport($slashless, $other);

        self::assertSame([$slashless, $other], $report->filterByArea('document')->problems());
        self::assertSame([], $report->filterByArea('doc')->problems());
    }

    public function testFiltersCanChain(): void
    {
        $warning = new Problem(ProblemSeverity::Warning, 'section/short-adornment', 'Underline too short.');
        $info = new Problem(ProblemSeverity::Info, 'section/style', 'Unusual adornment.');
        $error = new Problem(ProblemSeverity::Error, 'table/malformed', 'Malformed table.');

        $filtered = new ProblemReport($warning, $info, $error)
            ->filterByArea('section')
            ->filterBySeverity(ProblemSeverity::Warning);

        self::assertSame([$warning], $filtered->problems());
        self::assertSame(ProblemSeverity::Warning, $filtered->maxSeverity());
    }

    public function testCountsDiagnosticsBySeverityAndCode(): void
    {
        $report = new ProblemReport(
            new Problem(ProblemSeverity::Warning, 'section/short-adornment', 'Short.'),
            new Problem(ProblemSeverity::Error, 'table/malformed', 'Malformed.'),
            new Problem(ProblemSeverity::Warning, 'section/short-adornment', 'Short again.'),
            new Problem(ProblemSeverity::Info, 'reference/unresolved', 'Unresolved.'),
        );

        self::assertSame(
            ['severe' => 0, 'error' => 1, 'warning' => 2, 'info' => 1],
            $report->countsBySeverity(),
        );
        self::assertSame(
            ['section/short-adornment' => 2, 'reference/unresolved' => 1, 'table/malformed' => 1],
            $report->countsByCode(),
        );
    }

    public function testMergePreservesProblemOrder(): void
    {
        $first = new Problem(ProblemSeverity::Info, 'first', 'First.');
        $second = new Problem(ProblemSeverity::Warning, 'second', 'Second.');
        $left = new ProblemReport($first);
        $right = new ProblemReport($second);

        self::assertSame([$first, $second], $left->merge($right)->problems());
        self::assertSame([$first], $left->problems());
    }
}
