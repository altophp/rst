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

use Alto\Rst\Lint\Rule\NoTabRule;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoTabRule::class)]
final class NoTabRuleTest extends TestCase
{
    public function testCode(): void
    {
        self::assertSame('lint/no-tab', new NoTabRule()->code());
    }

    public function testSpacesOnlyAreAccepted(): void
    {
        self::assertSame([], self::lint("Paragraph.\n\n    Indented.\n"));
    }

    public function testTabInIndentationIsReported(): void
    {
        $problems = self::lint("Paragraph.\n\n\tIndented.\n");

        self::assertCount(1, $problems);
        self::assertSame(ProblemSeverity::Warning, $problems[0]->severity);
        self::assertSame('Tab character on line 3; use spaces.', $problems[0]->message);

        $span = $problems[0]->span;
        self::assertNotNull($span);
        self::assertSame(12, $span->start);
        self::assertSame(1, $span->length);
    }

    public function testTabInsideContentIsReported(): void
    {
        self::assertCount(1, self::lint("Before\tafter.\n"));
    }

    public function testOneProblemPerLine(): void
    {
        self::assertCount(1, self::lint("\tOne\ttab\treported.\n"));
    }

    /**
     * @return list<Problem>
     */
    private static function lint(string $rst): array
    {
        $document = Rst::symfony()->parse($rst)->document();
        $problems = new ProblemCollector();
        new NoTabRule()->check($document, Source::fromString($rst), $problems);

        return $problems->report()->problems();
    }
}
