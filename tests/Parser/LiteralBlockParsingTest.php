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

use Alto\Rst\Node\LiteralBlock;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversNamespace;

#[CoversNamespace('Alto\\Rst\\Parser')]
final class LiteralBlockParsingTest extends ParserTestCase
{
    public function testMinimizedMarkerKeepsOneColon(): void
    {
        $input = "Code::\n\n    x = 1\n";
        $result = self::parseRst($input);
        $children = $result->document()->children();

        self::assertCount(2, $children);
        $paragraph = $children[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertSame('Code:', $paragraph->text->text);

        $literal = $children[1];
        self::assertInstanceOf(LiteralBlock::class, $literal);
        self::assertSpan(8, 17, $literal);
        self::assertSame('    x = 1', Source::fromString($input)->slice($literal->content));
        self::assertNoProblems($result);
    }

    public function testExpandedMarkerIsDroppedFromTheParagraph(): void
    {
        $result = self::parseRst("Paragraph ::\n\n    lit\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        $paragraph = $children[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertSame('Paragraph', $paragraph->text->text);
        self::assertInstanceOf(LiteralBlock::class, $children[1]);
        self::assertNoProblems($result);
    }

    public function testColonPrefixedExpandedMarkerKeepsTheColon(): void
    {
        $result = self::parseRst("Options: ::\n\n    lit\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        $paragraph = $children[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertSame('Options:', $paragraph->text->text);
    }

    public function testStandaloneMarkerProducesNoParagraph(): void
    {
        $result = self::parseRst("::\n\n    literal\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $literal = $children[0];
        self::assertInstanceOf(LiteralBlock::class, $literal);
        self::assertSpan(0, 15, $literal);
        self::assertSame(4, $literal->content->start);
        self::assertNoProblems($result);
    }

    public function testMarkerOnOwnLineAfterParagraph(): void
    {
        $result = self::parseRst("Text\n::\n\n    lit\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        $paragraph = $children[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertSame('Text', $paragraph->text->text);

        $literal = $children[1];
        self::assertInstanceOf(LiteralBlock::class, $literal);
        self::assertSame(5, $literal->span()->start);

        // docutils also reports the two-character "::" line as a possible
        // title underline before falling back to ordinary text
        self::assertSame(['section/possible-underline'], self::problemCodes($result));
    }

    public function testContentSpanPreservesIndentationAndInnerBlankLines(): void
    {
        $input = "Code::\n\n    one\n\n      two\n\nAfter.\n";
        $result = self::parseRst($input);
        $children = $result->document()->children();

        self::assertCount(3, $children);
        $literal = $children[1];
        self::assertInstanceOf(LiteralBlock::class, $literal);
        self::assertSame("    one\n\n      two", Source::fromString($input)->slice($literal->content));
        self::assertNoProblems($result);
    }

    public function testMissingContentIsReported(): void
    {
        $result = self::parseRst("Code follows::\n\nNot indented.\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(Paragraph::class, $children[0]);
        self::assertInstanceOf(Paragraph::class, $children[1]);
        self::assertSame(['literal/missing-content'], self::problemCodes($result));
    }

    public function testMissingBlankLineIsReportedButTheBlockIsKept(): void
    {
        $result = self::parseRst("Code::\n    indented\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(Paragraph::class, $children[0]);
        self::assertInstanceOf(LiteralBlock::class, $children[1]);
        self::assertSame(['literal/missing-blank-line'], self::problemCodes($result));
    }

    public function testQuotedLiteralBlock(): void
    {
        $input = "Paragraph::\n\n> quoted literal\n> second line\n";
        $result = self::parseRst($input);
        $children = $result->document()->children();

        self::assertCount(2, $children);
        $literal = $children[1];
        self::assertInstanceOf(LiteralBlock::class, $literal);
        self::assertSame("> quoted literal\n> second line", Source::fromString($input)->slice($literal->content));
        self::assertNoProblems($result);
    }

    public function testInconsistentQuotedLiteralIsReported(): void
    {
        $result = self::parseRst("Para::\n\n> one\nplain\n");

        self::assertSame(['literal/inconsistent-quoting'], self::problemCodes($result));
    }

    public function testQuotedLiteralStopsBeforeDifferentlyQuotedProse(): void
    {
        $input = "Paragraph::\n\n> quoted literal\n! punctuation prose\n";
        $result = self::parseRst($input);
        $children = $result->document()->children();

        self::assertCount(3, $children);
        self::assertInstanceOf(LiteralBlock::class, $children[1]);
        self::assertSame('> quoted literal', Source::fromString($input)->slice($children[1]->content));
        self::assertInstanceOf(Paragraph::class, $children[2]);
        self::assertSame('! punctuation prose', $children[2]->text->text);
        self::assertSame(['literal/inconsistent-quoting'], self::problemCodes($result));
    }
}
