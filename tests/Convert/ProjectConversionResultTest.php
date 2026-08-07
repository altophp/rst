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
use Alto\Rst\Convert\ConversionResult;
use Alto\Rst\Convert\ConversionStatus;
use Alto\Rst\Convert\IssueKind;
use Alto\Rst\Convert\ProjectConversionResult;
use Alto\Rst\Convert\ProjectFileConversion;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemReport;
use Alto\Rst\Problem\ProblemSeverity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectConversionResult::class)]
#[CoversClass(ProjectFileConversion::class)]
final class ProjectConversionResultTest extends TestCase
{
    public function testDerivesAggregateStatusAndOverlappingQueuesFromFiles(): void
    {
        $diagnostic = new Problem(
            ProblemSeverity::Warning,
            'inline/unmatched-start-string',
            'Unmatched inline marker.',
        );
        $projectProblem = new Problem(
            ProblemSeverity::Error,
            'reference/unresolved-project-target',
            'Missing project target.',
        );
        $result = new ProjectConversionResult([
            $this->file('blocked.rst', [
                ConversionIssue::unsupported('directive:unknown', 'No equivalent.'),
                ConversionIssue::lossy('directive:image', 'Options dropped.'),
                ConversionIssue::approximated('role:ref', 'Rendered as a link.'),
            ]),
            $this->file('review.rst', [
                ConversionIssue::lossy('table:block-cell', 'Block structure flattened.'),
            ]),
            $this->file('tracked.rst', [
                ConversionIssue::approximated('directive:note', 'Rendered as a callout.'),
            ]),
            $this->file('exact.rst'),
            $this->file('diagnostic.rst', parseProblems: new ProblemReport($diagnostic)),
        ], new ProblemReport($projectProblem));

        self::assertSame(ConversionStatus::Blocked, $result->status());
        self::assertFalse($result->isComplete());
        self::assertFalse($result->isLossless());
        self::assertFalse($result->isExact());
        self::assertSame(
            ['unsupported' => 1, 'lossy' => 2, 'approximated' => 2],
            $result->report->countsByKind(),
        );
        self::assertSame(
            ['blocked.rst'],
            $this->paths($result->filesWithStatus(ConversionStatus::Blocked)),
        );
        self::assertSame(
            ['review.rst'],
            $this->paths($result->filesWithStatus(ConversionStatus::Review)),
        );
        self::assertSame(
            ['tracked.rst'],
            $this->paths($result->filesWithStatus(ConversionStatus::Tracked)),
        );
        self::assertSame(
            ['exact.rst', 'diagnostic.rst'],
            $this->paths($result->filesWithStatus(ConversionStatus::Exact)),
        );
        self::assertSame(
            ['blocked.rst', 'review.rst'],
            $this->paths($result->filesWithIssueKind(IssueKind::Lossy)),
        );
        self::assertSame(['diagnostic.rst'], $this->paths($result->filesWithDiagnostics()));
        self::assertSame(
            ['severe' => 0, 'error' => 0, 'warning' => 1, 'info' => 0],
            $result->parseProblems()->countsBySeverity(),
        );
        self::assertCount(0, $result->referenceProblems());
        self::assertSame([$projectProblem], $result->projectReferenceProblems->problems());
        self::assertTrue($result->file('exact.rst')?->isExact());
        self::assertNull($result->file('C:\\exact.rst'));
    }

    /**
     * @param list<ConversionIssue> $issues
     */
    private function file(
        string $sourcePath,
        array $issues = [],
        ?ProblemReport $parseProblems = null,
        ?ProblemReport $referenceProblems = null,
    ): ProjectFileConversion {
        return new ProjectFileConversion(
            $sourcePath,
            substr($sourcePath, 0, -4).'.md',
            new ConversionResult('', new ConversionReport($issues)),
            $parseProblems ?? new ProblemReport(),
            $referenceProblems ?? new ProblemReport(),
        );
    }

    /**
     * @param list<ProjectFileConversion> $files
     *
     * @return list<string>
     */
    private function paths(array $files): array
    {
        return array_map(
            static fn (ProjectFileConversion $file): string => $file->sourcePath,
            $files,
        );
    }
}
