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

namespace Alto\Rst\Tests\Convert\Markdown;

use Alto\Rst\Convert\Markdown\BlockDraft;
use Alto\Rst\Convert\Markdown\DocumentDraft;
use Alto\Rst\Convert\Markdown\MarkdownBlockParser;
use Alto\Rst\Convert\Markdown\MarkdownInlineItem;
use Alto\Rst\Convert\Markdown\MarkdownInlineParser;
use Alto\Rst\Convert\Markdown\MarkdownReader;
use Alto\Rst\Convert\Markdown\MdBlockQuote;
use Alto\Rst\Convert\Markdown\MdLineCursor;
use Alto\Rst\Convert\Markdown\MdParagraph;
use Alto\Rst\Convert\Markdown\MdText;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MarkdownReader::class)]
#[CoversClass(BlockDraft::class)]
#[CoversClass(DocumentDraft::class)]
#[CoversClass(MarkdownBlockParser::class)]
#[CoversClass(MarkdownInlineItem::class)]
#[CoversClass(MarkdownInlineParser::class)]
#[CoversClass(MdLineCursor::class)]
#[CoversClass(MdBlockQuote::class)]
final class MarkdownReaderBlockQuoteTest extends MarkdownReaderTestCase
{
    public function testSimpleBlockQuote(): void
    {
        $quote = self::read("> Quoted text.\n")->children()[0];
        self::assertInstanceOf(MdBlockQuote::class, $quote);

        $inner = $quote->children();
        self::assertCount(1, $inner);
        self::assertInstanceOf(MdParagraph::class, $inner[0]);
        $inline = $inner[0]->children();
        self::assertInstanceOf(MdText::class, $inline[0]);
        self::assertSame('Quoted text.', $inline[0]->text);
    }

    public function testBlockQuoteMarkerWithoutFollowingSpaceStillStripsMarker(): void
    {
        $quote = self::read(">Quoted.\n")->children()[0];
        self::assertInstanceOf(MdBlockQuote::class, $quote);
        $paragraph = $quote->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $inline = $paragraph->children()[0];
        self::assertInstanceOf(MdText::class, $inline);
        self::assertSame('Quoted.', $inline->text);
    }

    public function testLazyContinuationMergesIntoTheOpenParagraph(): void
    {
        $quote = self::read("> Line one\nLine two\n")->children()[0];
        self::assertInstanceOf(MdBlockQuote::class, $quote);

        $inner = $quote->children();
        self::assertCount(1, $inner);
        self::assertInstanceOf(MdParagraph::class, $inner[0]);
        $inline = $inner[0]->children()[0];
        self::assertInstanceOf(MdText::class, $inline);
        self::assertSame('Line one Line two', $inline->text);
    }

    public function testLazyContinuationStopsAtABlockStart(): void
    {
        $quote = self::read("> Line one\n# Heading\n")->children()[0];
        self::assertInstanceOf(MdBlockQuote::class, $quote);
        self::assertCount(1, $quote->children());
    }

    public function testBlankLineEndsTheBlockQuote(): void
    {
        $children = self::read("> Quoted.\n\nAfter.\n")->children();
        self::assertCount(2, $children);
        self::assertInstanceOf(MdBlockQuote::class, $children[0]);
        self::assertInstanceOf(MdParagraph::class, $children[1]);
    }

    public function testBlankMarkerLineSeparatesTwoParagraphsInsideTheQuote(): void
    {
        $quote = self::read("> First.\n>\n> Second.\n")->children()[0];
        self::assertInstanceOf(MdBlockQuote::class, $quote);
        self::assertCount(2, $quote->children());
    }

    public function testNestedBlockQuotes(): void
    {
        $outer = self::read("> > Nested.\n")->children()[0];
        self::assertInstanceOf(MdBlockQuote::class, $outer);
        $inner = $outer->children()[0];
        self::assertInstanceOf(MdBlockQuote::class, $inner);
        $paragraph = $inner->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $inline = $paragraph->children()[0];
        self::assertInstanceOf(MdText::class, $inline);
        self::assertSame('Nested.', $inline->text);
    }

    public function testBlockQuoteCanContainAHeading(): void
    {
        $quote = self::read("> # Heading\n")->children()[0];
        self::assertInstanceOf(MdBlockQuote::class, $quote);
        self::assertCount(1, $quote->children());
    }

    public function testBlockQuoteSpanStartsAtTheMarker(): void
    {
        $quote = self::read("> Quoted.\n")->children()[0];
        self::assertSpan(0, 9, $quote);
    }

    public function testHeavilyIndentedLazyContinuationStillJoinsTheParagraph(): void
    {
        $quote = self::read("> Text\n    indented\n")->children()[0];
        self::assertInstanceOf(MdBlockQuote::class, $quote);
        $paragraph = $quote->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $inline = $paragraph->children()[0];
        self::assertInstanceOf(MdText::class, $inline);
        self::assertSame('Text indented', $inline->text);
    }
}
