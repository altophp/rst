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

namespace Alto\Rst\Tests\Convert;

use Alto\Rst\Convert\ConversionIssue;
use Alto\Rst\Convert\ConversionReport;
use Alto\Rst\Convert\ConversionStatus;
use Alto\Rst\Convert\IssueKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConversionReport::class)]
#[CoversClass(ConversionStatus::class)]
final class ConversionReportTest extends TestCase
{
    public function testAnEmptyReportIsExact(): void
    {
        $report = new ConversionReport();

        self::assertTrue($report->isEmpty());
        self::assertSame(ConversionStatus::Exact, $report->status());
        self::assertTrue($report->isComplete());
        self::assertTrue($report->isLossless());
        self::assertTrue($report->isExact());
        self::assertSame([], $report->countsByConstruct());
    }

    public function testLossyIssueRequiresReviewAndBreaksLosslessness(): void
    {
        $report = new ConversionReport([
            ConversionIssue::lossy('directive:figure', 'Caption dropped.'),
            ConversionIssue::approximated('directive:note', 'Rendered as a GitHub alert.'),
        ]);

        self::assertFalse($report->isEmpty());
        self::assertSame(ConversionStatus::Review, $report->status());
        self::assertTrue($report->isComplete());
        self::assertFalse($report->isLossless());
        self::assertFalse($report->isExact());
    }

    public function testAnUnsupportedIssueBlocksCompleteness(): void
    {
        $report = new ConversionReport([
            ConversionIssue::lossy('directive:figure', 'Caption dropped.'),
            ConversionIssue::unsupported('directive:toctree', 'No equivalent.'),
        ]);

        self::assertSame(ConversionStatus::Blocked, $report->status());
        self::assertFalse($report->isComplete());
        self::assertFalse($report->isLossless());
        self::assertFalse($report->isExact());
    }

    public function testApproximationIsCompleteAndLosslessButNotExact(): void
    {
        $report = new ConversionReport([
            ConversionIssue::approximated('directive:note', 'Rendered as a GitHub alert.'),
        ]);

        self::assertSame(ConversionStatus::Tracked, $report->status());
        self::assertTrue($report->isComplete());
        self::assertTrue($report->isLossless());
        self::assertFalse($report->isExact());
        self::assertTrue($report->hasKind(IssueKind::Approximated));
        self::assertFalse($report->hasKind(IssueKind::Lossy));
    }

    public function testOfKindFiltersAndReindexes(): void
    {
        $report = new ConversionReport([
            ConversionIssue::lossy('a', 'm'),
            ConversionIssue::unsupported('b', 'm'),
            ConversionIssue::lossy('c', 'm'),
        ]);

        $lossy = $report->ofKind(IssueKind::Lossy);

        self::assertCount(2, $lossy);
        self::assertSame(['a', 'c'], array_map(static fn (ConversionIssue $i): string => $i->construct, $lossy));
        self::assertSame([], $report->ofKind(IssueKind::Approximated));
    }

    public function testCountsByConstructOrdersByDescendingCountThenKey(): void
    {
        $report = new ConversionReport([
            ConversionIssue::unsupported('directive:toctree', 'm'),
            ConversionIssue::unsupported('role:ref', 'm'),
            ConversionIssue::unsupported('directive:toctree', 'm'),
            ConversionIssue::unsupported('directive:toctree', 'm'),
            ConversionIssue::unsupported('node:Table', 'm'),
            ConversionIssue::unsupported('role:ref', 'm'),
        ]);

        self::assertSame(
            ['directive:toctree' => 3, 'role:ref' => 2, 'node:Table' => 1],
            $report->countsByConstruct(),
        );
    }

    public function testCountsByKindAndConstructKeepFidelitySeparate(): void
    {
        $report = new ConversionReport([
            ConversionIssue::approximated('role:ref', 'm'),
            ConversionIssue::lossy('directive:image', 'm'),
            ConversionIssue::lossy('directive:image', 'm'),
            ConversionIssue::approximated('role:doc', 'm'),
            ConversionIssue::approximated('role:ref', 'm'),
        ]);

        self::assertSame(
            ['unsupported' => 0, 'lossy' => 2, 'approximated' => 3],
            $report->countsByKind(),
        );
        self::assertSame(
            [
                'unsupported' => [],
                'lossy' => ['directive:image' => 2],
                'approximated' => ['role:ref' => 2, 'role:doc' => 1],
            ],
            $report->countsByKindAndConstruct(),
        );
    }

    public function testMergeConcatenatesIssuesInOrder(): void
    {
        $first = new ConversionReport([ConversionIssue::lossy('a', 'm')]);
        $second = new ConversionReport([ConversionIssue::unsupported('b', 'm')]);

        $merged = $first->merge($second);

        self::assertCount(2, $merged->issues);
        self::assertSame('a', $merged->issues[0]->construct);
        self::assertSame('b', $merged->issues[1]->construct);
        self::assertCount(1, $first->issues);
    }
}
