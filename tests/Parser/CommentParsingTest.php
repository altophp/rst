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

use Alto\Rst\Node\BlockQuote;
use Alto\Rst\Node\Comment;
use Alto\Rst\Node\FootnoteDefinition;
use Alto\Rst\Node\SubstitutionDefinition;
use PHPUnit\Framework\Attributes\CoversNamespace;

#[CoversNamespace('Alto\\Rst\\Parser')]
final class CommentParsingTest extends ParserTestCase
{
    public function testSingleLineComment(): void
    {
        $result = self::parseRst(".. just a comment\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $comment = $children[0];
        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame('just a comment', $comment->text);
        self::assertSpan(0, 17, $comment);
        self::assertNoProblems($result);
    }

    public function testCommentWithContinuation(): void
    {
        $result = self::parseRst(".. first line\n   second line\n\nAfter.\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        $comment = $children[0];
        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame("first line\nsecond line", $comment->text);
        self::assertNoProblems($result);
    }

    public function testCommentKeepsBlankSeparatedContinuation(): void
    {
        $result = self::parseRst(".. first\n\n   still the comment\n\nAfter.\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        $comment = $children[0];
        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame("first\n\nstill the comment", $comment->text);
        self::assertNoProblems($result);
    }

    public function testEmptyComment(): void
    {
        $result = self::parseRst("..\n\nAfter.\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        $comment = $children[0];
        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame('', $comment->text);
        self::assertSpan(0, 2, $comment);
        self::assertNoProblems($result);
    }

    public function testEmptyCommentDoesNotAbsorbABlankSeparatedBlock(): void
    {
        $result = self::parseRst("..\n\n   indented block\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(Comment::class, $children[0]);
        self::assertInstanceOf(BlockQuote::class, $children[1]);
        self::assertNoProblems($result);
    }

    public function testMarkerOnlyCommentWithAttachedBlock(): void
    {
        $result = self::parseRst("..\n   attached to the comment\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $comment = $children[0];
        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame('attached to the comment', $comment->text);
        self::assertNoProblems($result);
    }

    public function testFootnoteShapeParsesAsADefinition(): void
    {
        $result = self::parseRst(".. [1] a footnote\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(FootnoteDefinition::class, $children[0]);
        self::assertNoProblems($result);
    }

    public function testSubstitutionShapeParsesAsADefinition(): void
    {
        $result = self::parseRst(".. |name| replace:: value\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(SubstitutionDefinition::class, $children[0]);
        self::assertNoProblems($result);
    }
}
