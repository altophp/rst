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
use Alto\Rst\Problem\ProblemFormatter;
use Alto\Rst\Problem\ProblemReport;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProblemFormatter::class)]
final class ProblemFormatterTest extends TestCase
{
    public function testFormatsProblemWithSpan(): void
    {
        $problem = new Problem(
            ProblemSeverity::Warning,
            'section/short-adornment',
            'Title underline too short.',
            ByteSpan::between(10, 24),
        );

        self::assertSame(
            'warning [section/short-adornment] Title underline too short. (bytes 10..24)',
            new ProblemFormatter()->formatProblem($problem),
        );
    }

    public function testFormatsProblemWithoutSpan(): void
    {
        $problem = new Problem(ProblemSeverity::Info, 'document/empty', 'Document is empty.');

        self::assertSame(
            'info [document/empty] Document is empty.',
            new ProblemFormatter()->formatProblem($problem),
        );
    }

    public function testFormatsReportAsOneLinePerProblem(): void
    {
        $report = new ProblemReport(
            new Problem(ProblemSeverity::Error, 'table/malformed', 'Malformed table.', ByteSpan::between(0, 5)),
            new Problem(ProblemSeverity::Info, 'document/empty', 'Document is empty.'),
        );

        self::assertSame(
            "error [table/malformed] Malformed table. (bytes 0..5)\n"
            ."info [document/empty] Document is empty.\n",
            new ProblemFormatter()->format($report),
        );
    }

    public function testFormatsEmptyReportAsEmptyString(): void
    {
        self::assertSame('', new ProblemFormatter()->format(new ProblemReport()));
    }
}
