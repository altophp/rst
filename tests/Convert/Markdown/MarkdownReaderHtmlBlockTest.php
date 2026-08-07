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
use Alto\Rst\Convert\Markdown\MdHtmlBlock;
use Alto\Rst\Convert\Markdown\MdLineCursor;
use Alto\Rst\Convert\Markdown\MdParagraph;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MarkdownReader::class)]
#[CoversClass(BlockDraft::class)]
#[CoversClass(DocumentDraft::class)]
#[CoversClass(MarkdownBlockParser::class)]
#[CoversClass(MarkdownInlineItem::class)]
#[CoversClass(MarkdownInlineParser::class)]
#[CoversClass(MdLineCursor::class)]
#[CoversClass(MdHtmlBlock::class)]
final class MarkdownReaderHtmlBlockTest extends MarkdownReaderTestCase
{
    public function testHtmlBlockIsKeptVerbatim(): void
    {
        $markdown = "<div class=\"note\">\n  <p>Hello</p>\n</div>\n";
        $block = self::read($markdown)->children()[0];

        self::assertInstanceOf(MdHtmlBlock::class, $block);
        self::assertSame("<div class=\"note\">\n  <p>Hello</p>\n</div>", $block->content);
    }

    public function testHtmlBlockEndsAtABlankLine(): void
    {
        $children = self::read("<div>\ncontent\n</div>\n\nAfter.\n")->children();
        self::assertCount(2, $children);
        self::assertInstanceOf(MdHtmlBlock::class, $children[0]);
        self::assertInstanceOf(MdParagraph::class, $children[1]);
    }

    public function testHtmlCommentBlock(): void
    {
        $block = self::read("<!-- a comment -->\n")->children()[0];
        self::assertInstanceOf(MdHtmlBlock::class, $block);
        self::assertSame('<!-- a comment -->', $block->content);
    }

    public function testClosingTagStartsAnHtmlBlock(): void
    {
        $block = self::read("</div>\n")->children()[0];
        self::assertInstanceOf(MdHtmlBlock::class, $block);
    }

    public function testTextStartingWithAngleBracketButNotATagIsAParagraph(): void
    {
        $node = self::read("<3 hearts\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $node);
    }

    public function testProcessingInstructionBlock(): void
    {
        $block = self::read("<?php echo 'hi'; ?>\n")->children()[0];
        self::assertInstanceOf(MdHtmlBlock::class, $block);
        self::assertSame("<?php echo 'hi'; ?>", $block->content);
    }

    public function testDeclarationBlock(): void
    {
        $block = self::read("<!DOCTYPE html>\n")->children()[0];
        self::assertInstanceOf(MdHtmlBlock::class, $block);
        self::assertSame('<!DOCTYPE html>', $block->content);
    }

    public function testCdataBlock(): void
    {
        $block = self::read("<![CDATA[ some data ]]>\n")->children()[0];
        self::assertInstanceOf(MdHtmlBlock::class, $block);
        self::assertSame('<![CDATA[ some data ]]>', $block->content);
    }

    public function testKnownBlockTagWithTrailingTextStillStartsABlock(): void
    {
        $block = self::read("<div>text</div>\n")->children()[0];
        self::assertInstanceOf(MdHtmlBlock::class, $block);
    }
}
