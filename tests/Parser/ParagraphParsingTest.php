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
use Alto\Rst\Node\DefinitionList;
use Alto\Rst\Node\Paragraph;
use PHPUnit\Framework\Attributes\CoversNamespace;

#[CoversNamespace('Alto\\Rst\\Parser')]
final class ParagraphParsingTest extends ParserTestCase
{
    public function testSingleParagraph(): void
    {
        $result = self::parseRst("Just one paragraph.\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $paragraph = $children[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertSame('Just one paragraph.', $paragraph->text->text);
        self::assertSpan(0, 19, $paragraph);
        self::assertSpan(0, 19, $paragraph->text);
        self::assertNoProblems($result);
    }

    public function testMultiLineParagraphKeepsRawSourceText(): void
    {
        $result = self::parseRst("First line\nsecond line\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $paragraph = $children[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertSame("First line\nsecond line", $paragraph->text->text);
        self::assertSpan(0, 22, $paragraph);
        self::assertNoProblems($result);
    }

    public function testBlankLinesSeparateParagraphs(): void
    {
        $result = self::parseRst("One.\n\nTwo.\n\n\nThree.\n");
        $children = $result->document()->children();

        self::assertCount(3, $children);

        foreach ($children as $child) {
            self::assertInstanceOf(Paragraph::class, $child);
        }

        self::assertNoProblems($result);
    }

    public function testTrailingWhitespaceIsExcludedFromTextSpan(): void
    {
        $result = self::parseRst("Padded.   \n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $paragraph = $children[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertSame('Padded.', $paragraph->text->text);
        self::assertSpan(0, 7, $paragraph->text);
    }

    public function testShortDashLineIsOrdinaryText(): void
    {
        $result = self::parseRst("para\n\n---\n\npara2\n");
        $children = $result->document()->children();

        self::assertCount(3, $children);
        $middle = $children[1];
        self::assertInstanceOf(Paragraph::class, $middle);
        self::assertSame('---', $middle->text->text);
        self::assertNoProblems($result);
    }

    public function testDefinitionListShapeProducesADefinitionList(): void
    {
        $result = self::parseRst("term\n    definition\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(DefinitionList::class, $children[0]);
        self::assertSame('term', $children[0]->children()[0]->term->text);
        self::assertNoProblems($result);
    }

    public function testUnexpectedIndentationAfterParagraphIsReported(): void
    {
        $result = self::parseRst("one\ntwo\n    three\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(Paragraph::class, $children[0]);
        self::assertInstanceOf(BlockQuote::class, $children[1]);
        self::assertSame(['parser/unexpected-indentation'], self::problemCodes($result));
    }

    public function testFieldListShapeIsReportedAsUnsupported(): void
    {
        $result = self::parseRst(":author: Someone\n:date: today\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(Paragraph::class, $children[0]);
        self::assertSame(['parser/unsupported-construct'], self::problemCodes($result));
    }

    public function testLineBlockShapeIsReportedAsUnsupported(): void
    {
        $result = self::parseRst("| a line of verse\n| another line\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(Paragraph::class, $children[0]);
        self::assertSame(['parser/unsupported-construct'], self::problemCodes($result));
    }

    public function testGridTableShapeProducesATable(): void
    {
        $result = self::parseRst("+-----+-----+\n| a   | b   |\n+-----+-----+\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(\Alto\Rst\Node\Table::class, $children[0]);
        self::assertNoProblems($result);
    }
}
