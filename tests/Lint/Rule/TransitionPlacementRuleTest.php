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

use Alto\Rst\Lint\Rule\TransitionPlacementRule;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Section;
use Alto\Rst\Node\Text;
use Alto\Rst\Node\Title;
use Alto\Rst\Node\Transition;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransitionPlacementRule::class)]
final class TransitionPlacementRuleTest extends TestCase
{
    public function testCode(): void
    {
        self::assertSame('lint/transition-placement', new TransitionPlacementRule()->code());
    }

    public function testTransitionInTheMiddleIsAccepted(): void
    {
        $document = new Document(ByteSpan::of(0, 30), [
            self::paragraph(0, 'Before'),
            new Transition(ByteSpan::of(8, 4)),
            self::paragraph(14, 'After'),
        ]);

        $problems = new ProblemCollector();
        new TransitionPlacementRule()->check($document, $problems);

        self::assertCount(0, $problems);
    }

    public function testTransitionAsFirstChildIsReported(): void
    {
        $transition = new Transition(ByteSpan::of(0, 4));
        $document = new Document(ByteSpan::of(0, 20), [
            $transition,
            self::paragraph(6, 'After'),
        ]);

        $problems = new ProblemCollector();
        new TransitionPlacementRule()->check($document, $problems);

        $problem = $problems->report()->problems()[0];
        self::assertCount(1, $problems);
        self::assertSame('lint/transition-placement', $problem->code);
        self::assertSame(ProblemSeverity::Warning, $problem->severity);
        self::assertSame($transition->span(), $problem->span);
    }

    public function testTransitionAsLastChildIsReported(): void
    {
        $transition = new Transition(ByteSpan::of(10, 4));
        $document = new Document(ByteSpan::of(0, 14), [
            self::paragraph(0, 'Before'),
            $transition,
        ]);

        $problems = new ProblemCollector();
        new TransitionPlacementRule()->check($document, $problems);

        $problem = $problems->report()->problems()[0];
        self::assertCount(1, $problems);
        self::assertSame(ProblemSeverity::Warning, $problem->severity);
        self::assertSame($transition->span(), $problem->span);
    }

    public function testAdjacentTransitionsAreReportedOnTheSecond(): void
    {
        $second = new Transition(ByteSpan::of(14, 4));
        $document = new Document(ByteSpan::of(0, 30), [
            self::paragraph(0, 'Before'),
            new Transition(ByteSpan::of(8, 4)),
            $second,
            self::paragraph(20, 'After'),
        ]);

        $problems = new ProblemCollector();
        new TransitionPlacementRule()->check($document, $problems);

        $problem = $problems->report()->problems()[0];
        self::assertCount(1, $problems);
        self::assertSame(ProblemSeverity::Warning, $problem->severity);
        self::assertSame($second->span(), $problem->span);
    }

    public function testAdjacentTransitionsInsideASectionAreReported(): void
    {
        $second = new Transition(ByteSpan::of(30, 4));
        $section = new Section(
            ByteSpan::of(0, 40),
            1,
            self::title(0, 'Intro'),
            [
                self::paragraph(12, 'Body'),
                new Transition(ByteSpan::of(24, 4)),
                $second,
                self::paragraph(36, 'More'),
            ],
            '=',
            false,
        );
        $document = new Document(ByteSpan::of(0, 40), [$section]);

        $problems = new ProblemCollector();
        new TransitionPlacementRule()->check($document, $problems);

        $problem = $problems->report()->problems()[0];
        self::assertCount(1, $problems);
        self::assertSame($second->span(), $problem->span);
    }

    public function testLoneTransitionIsReportedOnce(): void
    {
        $transition = new Transition(ByteSpan::of(0, 4));
        $document = new Document(ByteSpan::of(0, 4), [$transition]);

        $problems = new ProblemCollector();
        new TransitionPlacementRule()->check($document, $problems);

        self::assertCount(1, $problems);
    }

    private static function paragraph(int $start, string $text): Paragraph
    {
        $span = ByteSpan::of($start, strlen($text));

        return new Paragraph($span, new Text($span, $text));
    }

    private static function title(int $start, string $text): Title
    {
        $span = ByteSpan::of($start, strlen($text));

        return new Title($span, new Text($span, $text));
    }
}
