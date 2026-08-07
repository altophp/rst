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

namespace Alto\Rst\Tests\Parser;

use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Transition;
use PHPUnit\Framework\Attributes\CoversNamespace;

#[CoversNamespace('Alto\\Rst\\Parser')]
final class TransitionParsingTest extends ParserTestCase
{
    public function testTransitionBetweenParagraphs(): void
    {
        $result = self::parseRst("Before.\n\n----\n\nAfter.\n");
        $children = $result->document()->children();

        self::assertCount(3, $children);
        $transition = $children[1];
        self::assertInstanceOf(Transition::class, $transition);
        self::assertSpan(9, 13, $transition);
        self::assertNoProblems($result);
    }

    public function testLongerAndOtherPunctuationRuns(): void
    {
        $result = self::parseRst("Before.\n\n~~~~~~~~~~\n\nAfter.\n");
        $children = $result->document()->children();

        self::assertCount(3, $children);
        self::assertInstanceOf(Transition::class, $children[1]);
        self::assertNoProblems($result);
    }

    public function testTransitionAtDocumentStartIsKeptWithWarning(): void
    {
        $result = self::parseRst("----\n\nAfter.\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(Transition::class, $children[0]);
        self::assertSame(['transition/at-start'], self::problemCodes($result));
    }

    public function testTransitionAtDocumentEndIsKeptWithWarning(): void
    {
        $result = self::parseRst("Before.\n\n----\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(Transition::class, $children[1]);
        self::assertSame(['transition/at-end'], self::problemCodes($result));
    }

    public function testTransitionRightAfterASectionTitleWarns(): void
    {
        $result = self::parseRst("Title\n=====\n\n----\n\nBody.\n");

        self::assertSame(['transition/at-start'], self::problemCodes($result));
    }

    public function testThreeCharacterRunIsNotATransition(): void
    {
        $result = self::parseRst("Before.\n\n---\n\nAfter.\n");
        $children = $result->document()->children();

        self::assertCount(3, $children);
        self::assertInstanceOf(Paragraph::class, $children[1]);
        self::assertNoProblems($result);
    }
}
