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

namespace Alto\Rst\Tests\Lint\Rule;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Lint\Rule\MaxBlankLinesRule;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaxBlankLinesRule::class)]
final class MaxBlankLinesRuleTest extends TestCase
{
    public function testCode(): void
    {
        self::assertSame('lint/max-blank-lines', new MaxBlankLinesRule()->code());
    }

    public function testTwoBlankLinesAreAccepted(): void
    {
        self::assertSame([], self::lint("First.\n\n\nSecond.\n"));
    }

    public function testThreeBlankLinesAreReportedOnce(): void
    {
        $problems = self::lint("First.\n\n\n\nSecond.\n");

        self::assertCount(1, $problems);
        self::assertSame(ProblemSeverity::Info, $problems[0]->severity);
        self::assertSame('3 consecutive blank lines exceed the maximum of 2.', $problems[0]->message);
    }

    public function testTrailingRunIsReported(): void
    {
        self::assertCount(1, self::lint("First.\n\n\n\n"));
    }

    public function testWhitespaceOnlyLinesCountAsBlank(): void
    {
        self::assertCount(1, self::lint("First.\n\n  \n\nSecond.\n"));
    }

    public function testTheMaximumIsConfigurable(): void
    {
        $rule = new MaxBlankLinesRule(1);

        self::assertSame([], self::lint("First.\n\nSecond.\n", $rule));
        self::assertCount(1, self::lint("First.\n\n\nSecond.\n", $rule));
    }

    public function testInvalidMaximumIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MaxBlankLinesRule(0);
    }

    /**
     * @return list<Problem>
     */
    private static function lint(string $rst, ?MaxBlankLinesRule $rule = null): array
    {
        $document = Rst::symfony()->parse($rst)->document();
        $problems = new ProblemCollector();
        ($rule ?? new MaxBlankLinesRule())->check($document, Source::fromString($rst), $problems);

        return $problems->report()->problems();
    }
}
