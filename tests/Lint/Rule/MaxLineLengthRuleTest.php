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
use Alto\Rst\Lint\Rule\MaxLineLengthRule;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaxLineLengthRule::class)]
final class MaxLineLengthRuleTest extends TestCase
{
    public function testCode(): void
    {
        self::assertSame('lint/max-line-length', new MaxLineLengthRule()->code());
    }

    public function testLinesAtTheLimitAreAccepted(): void
    {
        self::assertSame([], self::lint(str_repeat('a', 80)."\n"));
    }

    public function testLongLinesAreReported(): void
    {
        $problems = self::lint(str_repeat('a', 81)."\n");

        self::assertCount(1, $problems);
        self::assertSame(ProblemSeverity::Info, $problems[0]->severity);
        self::assertSame('Line 1 exceeds 80 characters (found 81).', $problems[0]->message);
    }

    public function testTheLimitIsConfigurable(): void
    {
        $rule = new MaxLineLengthRule(20);

        self::assertSame([], self::lint(str_repeat('a', 20)."\n", $rule));
        self::assertCount(1, self::lint(str_repeat('a', 21)."\n", $rule));
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        // 80 two-byte characters: 160 bytes, exactly at the limit.
        self::assertSame([], self::lint(str_repeat("\u{00E9}", 80)."\n"));
    }

    public function testInvalidLimitIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MaxLineLengthRule(0);
    }

    /**
     * @return list<Problem>
     */
    private static function lint(string $rst, ?MaxLineLengthRule $rule = null): array
    {
        $document = Rst::symfony()->parse($rst)->document();
        $problems = new ProblemCollector();
        ($rule ?? new MaxLineLengthRule())->check($document, Source::fromString($rst), $problems);

        return $problems->report()->problems();
    }
}
