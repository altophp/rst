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
use Alto\Rst\Convert\Markdown\MdDocument;
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
#[CoversClass(MdParagraph::class)]
#[CoversClass(MdDocument::class)]
final class MarkdownReaderParagraphTest extends MarkdownReaderTestCase
{
    public function testSingleLineParagraph(): void
    {
        $document = self::read("Hello world.\n");
        $children = $document->children();

        self::assertCount(1, $children);
        $paragraph = $children[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $inline = $paragraph->children();
        self::assertInstanceOf(MdText::class, $inline[0]);
        self::assertSame('Hello world.', $inline[0]->text);
    }

    public function testMultiLineParagraphJoinsLinesWithASpace(): void
    {
        $paragraph = self::read("Hello\nworld.\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $inline = $paragraph->children();
        self::assertInstanceOf(MdText::class, $inline[0]);
        self::assertSame('Hello world.', $inline[0]->text);
    }

    public function testBlankLineSeparatesParagraphs(): void
    {
        $children = self::read("First.\n\nSecond.\n")->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(MdParagraph::class, $children[0]);
        self::assertInstanceOf(MdParagraph::class, $children[1]);
    }

    public function testParagraphSpanIncludesUpToThreeLeadingSpacesButTextDoesNot(): void
    {
        $paragraph = self::read("  Hello.\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        self::assertSpan(0, 8, $paragraph);

        $text = $paragraph->children()[0];
        self::assertInstanceOf(MdText::class, $text);
        self::assertSpan(2, 8, $text);
    }

    public function testEmptyDocumentHasNoChildren(): void
    {
        self::assertSame([], self::read('')->children());
    }

    public function testEmptyDocumentHasNoLinkReferenceDefinitions(): void
    {
        self::assertSame([], self::read('')->linkReferenceDefinitions);
    }

    public function testBlankOnlyDocumentHasNoChildren(): void
    {
        self::assertSame([], self::read("\n\n   \n")->children());
    }

    public function testDocumentSpanCoversTheWholeInput(): void
    {
        $document = self::read("Hello.\n");
        self::assertSpan(0, 7, $document);
    }
}
