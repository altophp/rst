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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConversionResult::class)]
final class ConversionResultTest extends TestCase
{
    public function testCarriesOutputAndReport(): void
    {
        $report = new ConversionReport();
        $result = new ConversionResult("# Title\n", $report);

        self::assertSame("# Title\n", $result->output);
        self::assertSame($report, $result->report);
        self::assertSame(ConversionStatus::Exact, $result->status());
        self::assertTrue($result->isComplete());
        self::assertTrue($result->isLossless());
        self::assertTrue($result->isExact());
    }

    public function testDelegatesLosslessnessToTheReport(): void
    {
        $result = new ConversionResult('', new ConversionReport([
            ConversionIssue::unsupported('directive:toctree', 'No equivalent.'),
        ]));

        self::assertFalse($result->isLossless());
        self::assertFalse($result->isComplete());
        self::assertFalse($result->isExact());
    }
}
