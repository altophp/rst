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
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Problem::class)]
#[CoversClass(ProblemSeverity::class)]
final class ProblemTest extends TestCase
{
    public function testSeverityLevelsMirrorDocutils(): void
    {
        self::assertSame(1, ProblemSeverity::Info->level());
        self::assertSame(2, ProblemSeverity::Warning->level());
        self::assertSame(3, ProblemSeverity::Error->level());
        self::assertSame(4, ProblemSeverity::Severe->level());
    }

    public function testIsAtLeast(): void
    {
        self::assertTrue(ProblemSeverity::Error->isAtLeast(ProblemSeverity::Warning));
        self::assertTrue(ProblemSeverity::Error->isAtLeast(ProblemSeverity::Error));
        self::assertFalse(ProblemSeverity::Info->isAtLeast(ProblemSeverity::Warning));
    }

    public function testProblemCarriesPosition(): void
    {
        $problem = new Problem(
            ProblemSeverity::Warning,
            'section/short-adornment',
            'Title underline too short.',
            ByteSpan::between(10, 14),
        );

        self::assertSame('section/short-adornment', $problem->code);
        self::assertSame(10, $problem->span?->start);
    }

    public function testProblemWithoutPosition(): void
    {
        $problem = new Problem(ProblemSeverity::Info, 'document/empty', 'Document is empty.');

        self::assertNull($problem->span);
    }
}
