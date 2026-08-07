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
use Alto\Rst\Convert\Markdown\MdCodeBlock;
use Alto\Rst\Convert\Markdown\MdCodeBlockStyle;
use Alto\Rst\Convert\Markdown\MdLineCursor;
use Alto\Rst\Convert\Markdown\MdParagraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(MarkdownReader::class)]
#[CoversClass(BlockDraft::class)]
#[CoversClass(DocumentDraft::class)]
#[CoversClass(MarkdownBlockParser::class)]
#[CoversClass(MarkdownInlineItem::class)]
#[CoversClass(MarkdownInlineParser::class)]
#[CoversClass(MdLineCursor::class)]
#[CoversClass(MdCodeBlock::class)]
#[CoversClass(MdCodeBlockStyle::class)]
final class MarkdownReaderCodeBlockTest extends MarkdownReaderTestCase
{
    #[DataProvider('fenceChars')]
    public function testFencedCodeBlockCapturesLanguageAndContent(string $fence): void
    {
        $markdown = "{$fence}{$fence}{$fence}php\n\$x = 1;\necho \$x;\n{$fence}{$fence}{$fence}\n";
        $block = self::read($markdown)->children()[0];

        self::assertInstanceOf(MdCodeBlock::class, $block);
        self::assertSame(MdCodeBlockStyle::Fenced, $block->style);
        self::assertSame('php', $block->infoString);
        self::assertSame("\$x = 1;\necho \$x;", $block->content);
        self::assertSame($fence, $block->fenceChar);
        self::assertSame(3, $block->fenceLength);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fenceChars(): iterable
    {
        yield 'backtick' => ['`'];
        yield 'tilde' => ['~'];
    }

    public function testFencedCodeBlockWithoutInfoStringHasNullInfoString(): void
    {
        $block = self::read("```\ncode\n```\n")->children()[0];
        self::assertInstanceOf(MdCodeBlock::class, $block);
        self::assertNull($block->infoString);
    }

    public function testFencedCodeBlockWithoutClosingFenceRunsToEndOfDocument(): void
    {
        $block = self::read("```\nunterminated\n")->children()[0];
        self::assertInstanceOf(MdCodeBlock::class, $block);
        self::assertSame('unterminated', $block->content);
    }

    public function testLongerFenceRunIsCaptured(): void
    {
        $block = self::read("````\ncode\n````\n")->children()[0];
        self::assertInstanceOf(MdCodeBlock::class, $block);
        self::assertSame(4, $block->fenceLength);
    }

    public function testClosingFenceMustBeAtLeastAsLongAsOpening(): void
    {
        $block = self::read("````\ncode\n```\nmore\n````\n")->children()[0];
        self::assertInstanceOf(MdCodeBlock::class, $block);
        self::assertSame("code\n```\nmore", $block->content);
    }

    public function testBacktickInfoStringWithBacktickIsNotAFence(): void
    {
        $node = self::read("```code`here\ntext\n```\n")->children()[0];
        self::assertNotInstanceOf(MdCodeBlock::class, $node);
    }

    public function testFencedCodeBlockStripsTheOpeningFenceIndentation(): void
    {
        $markdown = "  ```\n  first\n    second\n  ```\n";
        $block = self::read($markdown)->children()[0];
        self::assertInstanceOf(MdCodeBlock::class, $block);
        self::assertSame("first\n  second", $block->content);
    }

    public function testIndentedCodeBlock(): void
    {
        $block = self::read("    echo 1;\n    echo 2;\n")->children()[0];
        self::assertInstanceOf(MdCodeBlock::class, $block);
        self::assertSame(MdCodeBlockStyle::Indented, $block->style);
        self::assertSame("echo 1;\necho 2;", $block->content);
        self::assertNull($block->infoString);
        self::assertNull($block->fenceChar);
    }

    public function testIndentedCodeBlockKeepsExtraIndentationBeyondFourColumns(): void
    {
        $block = self::read("        deep\n")->children()[0];
        self::assertInstanceOf(MdCodeBlock::class, $block);
        self::assertSame('    deep', $block->content);
    }

    public function testIndentedCodeBlockDoesNotInterruptAParagraph(): void
    {
        $children = self::read("Paragraph\n    still paragraph\n")->children();
        self::assertCount(1, $children);
    }

    public function testBlankLinesInsideIndentedCodeBlockAreKept(): void
    {
        $block = self::read("    one\n\n    two\n")->children()[0];
        self::assertInstanceOf(MdCodeBlock::class, $block);
        self::assertSame("one\n\ntwo", $block->content);
    }

    public function testIndentedCodeBlockEndsAtALessIndentedLine(): void
    {
        $children = self::read("    code\ntext\n")->children();
        self::assertCount(2, $children);
        self::assertInstanceOf(MdCodeBlock::class, $children[0]);
        self::assertSame('code', $children[0]->content);
        self::assertInstanceOf(MdParagraph::class, $children[1]);
    }

    public function testIndentedCodeBlockDropsATrailingBlankLineBeforeUnrelatedContent(): void
    {
        $children = self::read("    code\n\ntext\n")->children();
        self::assertCount(2, $children);
        self::assertInstanceOf(MdCodeBlock::class, $children[0]);
        self::assertSame('code', $children[0]->content);
    }

    public function testFencedCodeBlockLeavesALessIndentedContentLineUnchanged(): void
    {
        $block = self::read("  ```\ncode\n  ```\n")->children()[0];
        self::assertInstanceOf(MdCodeBlock::class, $block);
        self::assertSame('code', $block->content);
    }
}
