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
use Alto\Rst\Lint\Rule\IndentationRule;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndentationRule::class)]
final class IndentationRuleTest extends TestCase
{
    public function testCode(): void
    {
        self::assertSame('lint/indentation', new IndentationRule()->code());
    }

    public function testFourSpaceIndentationIsAccepted(): void
    {
        self::assertSame([], self::lint(".. note::\n\n    Indented by four.\n"));
    }

    public function testThreeSpaceDirectiveBodyIsReported(): void
    {
        $problems = self::lint(".. note::\n\n   Indented by three.\n");

        self::assertCount(1, $problems);
        self::assertSame(ProblemSeverity::Info, $problems[0]->severity);
        self::assertSame('Indentation of 3 on line 3 is not a multiple of 4.', $problems[0]->message);
    }

    public function testCodeBlockBodyIsSkipped(): void
    {
        self::assertSame([], self::lint(".. code-block:: yaml\n\n    services:\n      app.service: ~\n"));
    }

    public function testBulletListContinuationIsSkipped(): void
    {
        self::assertSame([], self::lint("- item one\n  continued\n- item two\n"));
    }

    public function testCommentBodyIsSkipped(): void
    {
        self::assertSame([], self::lint(".. a comment\n   continued at three\n"));
    }

    public function testTheSizeIsConfigurable(): void
    {
        $rule = new IndentationRule(2);

        self::assertSame([], self::lint(".. note::\n\n  Indented by two.\n", $rule));
        self::assertCount(1, self::lint(".. note::\n\n   Indented by three.\n", $rule));
    }

    public function testInvalidSizeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IndentationRule(0);
    }

    /**
     * @return list<Problem>
     */
    private static function lint(string $rst, ?IndentationRule $rule = null): array
    {
        $document = Rst::symfony()->parse($rst)->document();
        $problems = new ProblemCollector();
        ($rule ?? new IndentationRule())->check($document, Source::fromString($rst), $problems);

        return $problems->report()->problems();
    }
}
