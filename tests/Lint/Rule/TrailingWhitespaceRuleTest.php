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

use Alto\Rst\Lint\Rule\TrailingWhitespaceRule;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrailingWhitespaceRule::class)]
final class TrailingWhitespaceRuleTest extends TestCase
{
    public function testCode(): void
    {
        self::assertSame('lint/trailing-whitespace', new TrailingWhitespaceRule()->code());
    }

    public function testCleanLinesAreAccepted(): void
    {
        self::assertSame([], self::lint("First line.\n\nSecond line.\n"));
    }

    public function testTrailingSpacesAreReported(): void
    {
        $problems = self::lint("Padded.  \nClean.\n");

        self::assertCount(1, $problems);
        self::assertSame(ProblemSeverity::Info, $problems[0]->severity);
        self::assertSame('Trailing whitespace on line 1.', $problems[0]->message);

        $span = $problems[0]->span;
        self::assertNotNull($span);
        self::assertSame(7, $span->start);
        self::assertSame(2, $span->length);
    }

    public function testTrailingTabIsReported(): void
    {
        self::assertCount(1, self::lint("Padded.\t\n"));
    }

    public function testWhitespaceOnlyLineIsReported(): void
    {
        self::assertCount(1, self::lint("First.\n   \nSecond.\n"));
    }

    /**
     * @return list<Problem>
     */
    private static function lint(string $rst): array
    {
        $document = Rst::symfony()->parse($rst)->document();
        $problems = new ProblemCollector();
        new TrailingWhitespaceRule()->check($document, Source::fromString($rst), $problems);

        return $problems->report()->problems();
    }
}
