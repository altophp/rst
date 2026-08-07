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

use Alto\Rst\Lint\Rule\AmericanEnglishRule;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AmericanEnglishRule::class)]
final class AmericanEnglishRuleTest extends TestCase
{
    public function testCode(): void
    {
        self::assertSame('lint/american-english', new AmericanEnglishRule()->code());
    }

    public function testAmericanSpellingsAreAccepted(): void
    {
        self::assertSame([], self::lint("This behavior has a color and a flavor.\n"));
    }

    public function testBritishSpellingIsReported(): void
    {
        $problems = self::lint("This behaviour is odd.\n");

        self::assertCount(1, $problems);
        self::assertSame(ProblemSeverity::Info, $problems[0]->severity);
        self::assertSame('Use the American English "behavior" instead of "behaviour".', $problems[0]->message);

        $span = $problems[0]->span;
        self::assertNotNull($span);
        self::assertSame(5, $span->start);
        self::assertSame(9, $span->length);
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $problems = self::lint("Colour me surprised.\n");

        self::assertCount(1, $problems);
        self::assertSame('Use the American English "color" instead of "Colour".', $problems[0]->message);
    }

    public function testEveryOccurrenceIsReported(): void
    {
        self::assertCount(2, self::lint("One colour here.\n\nAnother colour there.\n"));
    }

    public function testDerivedFormsAreReported(): void
    {
        self::assertCount(1, self::lint("The behaviours differ.\n"));
    }

    /**
     * @return list<Problem>
     */
    private static function lint(string $rst): array
    {
        $document = Rst::symfony()->parse($rst)->document();
        $problems = new ProblemCollector();
        new AmericanEnglishRule()->check($document, Source::fromString($rst), $problems);

        return $problems->report()->problems();
    }
}
