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

use Alto\Rst\Node\HyperlinkTarget;
use PHPUnit\Framework\Attributes\CoversNamespace;

#[CoversNamespace('Alto\\Rst\\Parser')]
final class HyperlinkTargetParsingTest extends ParserTestCase
{
    public function testMalformedTargetDeclarationFallsBackToAComment(): void
    {
        $result = self::parseRst(".. _missing-colon\n");

        self::assertInstanceOf(\Alto\Rst\Node\Comment::class, $result->document()->children()[0]);
    }

    public function testExternalTarget(): void
    {
        $result = self::parseRst(".. _example: https://example.com/\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $target = $children[0];
        self::assertInstanceOf(HyperlinkTarget::class, $target);
        self::assertSame('example', $target->name);
        self::assertSame('https://example.com/', $target->target);
        self::assertFalse($target->anonymous);
        self::assertSpan(0, 33, $target);
        self::assertNoProblems($result);
    }

    public function testInternalTargetHasEmptyTarget(): void
    {
        $result = self::parseRst(".. _anchor:\n\nParagraph.\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        $target = $children[0];
        self::assertInstanceOf(HyperlinkTarget::class, $target);
        self::assertSame('anchor', $target->name);
        self::assertSame('', $target->target);
        self::assertFalse($target->anonymous);
        self::assertNoProblems($result);
    }

    public function testAnonymousTarget(): void
    {
        $result = self::parseRst(".. __: https://example.com/\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $target = $children[0];
        self::assertInstanceOf(HyperlinkTarget::class, $target);
        self::assertSame('', $target->name);
        self::assertSame('https://example.com/', $target->target);
        self::assertTrue($target->anonymous);
        self::assertNoProblems($result);
    }

    public function testAnonymousShortForm(): void
    {
        $result = self::parseRst("__ https://example.com/\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $target = $children[0];
        self::assertInstanceOf(HyperlinkTarget::class, $target);
        self::assertSame('', $target->name);
        self::assertSame('https://example.com/', $target->target);
        self::assertTrue($target->anonymous);
        self::assertNoProblems($result);
    }

    public function testPhraseNameInBackticks(): void
    {
        $result = self::parseRst(".. _`a phrase: with colon`: https://example.com/\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $target = $children[0];
        self::assertInstanceOf(HyperlinkTarget::class, $target);
        self::assertSame('a phrase: with colon', $target->name);
        self::assertSame('https://example.com/', $target->target);
        self::assertNoProblems($result);
    }

    public function testEscapedColonInName(): void
    {
        $result = self::parseRst(".. _name\\: still: https://example.com/\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $target = $children[0];
        self::assertInstanceOf(HyperlinkTarget::class, $target);
        self::assertSame('name: still', $target->name);
        self::assertNoProblems($result);
    }

    public function testMultiLineTargetUrlIsJoinedWithoutSpaces(): void
    {
        $result = self::parseRst(".. _long: https://example.com/a/very/\n   long/path\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $target = $children[0];
        self::assertInstanceOf(HyperlinkTarget::class, $target);
        self::assertSame('https://example.com/a/very/long/path', $target->target);
        self::assertNoProblems($result);
    }

    public function testConsecutiveTargets(): void
    {
        $result = self::parseRst(".. _first:\n.. _second: https://example.com/\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(HyperlinkTarget::class, $children[0]);
        self::assertInstanceOf(HyperlinkTarget::class, $children[1]);
        self::assertNoProblems($result);
    }

    public function testIndirectTargetKeepsTheReferenceText(): void
    {
        $result = self::parseRst(".. _indirect: other_\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $target = $children[0];
        self::assertInstanceOf(HyperlinkTarget::class, $target);
        self::assertSame('other_', $target->target);
        self::assertNoProblems($result);
    }
}
