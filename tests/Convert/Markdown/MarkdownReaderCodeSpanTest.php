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
use Alto\Rst\Convert\Markdown\MdCode;
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
#[CoversClass(MdCode::class)]
final class MarkdownReaderCodeSpanTest extends MarkdownReaderTestCase
{
    public function testSimpleCodeSpan(): void
    {
        $paragraph = self::read("`code`\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $code = $paragraph->children()[0];
        self::assertInstanceOf(MdCode::class, $code);
        self::assertSame('code', $code->text);
        self::assertSame(1, $code->backtickCount);
    }

    public function testCodeSpanWithBacktickInsideUsesALongerRun(): void
    {
        $paragraph = self::read("``code with ` backtick``\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $code = $paragraph->children()[0];
        self::assertInstanceOf(MdCode::class, $code);
        self::assertSame('code with ` backtick', $code->text);
        self::assertSame(2, $code->backtickCount);
    }

    public function testCodeSpanStripsOneLeadingAndTrailingSpace(): void
    {
        $paragraph = self::read("` code `\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $code = $paragraph->children()[0];
        self::assertInstanceOf(MdCode::class, $code);
        self::assertSame('code', $code->text);
    }

    public function testCodeSpanOfOnlySpacesIsNotStripped(): void
    {
        $paragraph = self::read("` `\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $code = $paragraph->children()[0];
        self::assertInstanceOf(MdCode::class, $code);
        self::assertSame(' ', $code->text);
    }

    public function testUnmatchedBacktickRunIsLiteralText(): void
    {
        $paragraph = self::read("``unterminated\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $text = $paragraph->children()[0];
        self::assertInstanceOf(MdText::class, $text);
        self::assertSame('``unterminated', $text->text);
    }

    public function testCodeSpanWithOnlyALongerTrailingBacktickRunIsLiteralText(): void
    {
        $paragraph = self::read("`ab``\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $text = $paragraph->children()[0];
        self::assertInstanceOf(MdText::class, $text);
        self::assertSame('`ab``', $text->text);
    }

    public function testCodeSpanDoesNotProcessEmphasisInside(): void
    {
        $paragraph = self::read("`*not emphasis*`\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $code = $paragraph->children()[0];
        self::assertInstanceOf(MdCode::class, $code);
        self::assertSame('*not emphasis*', $code->text);
    }

    public function testCodeSpanSpan(): void
    {
        $paragraph = self::read("`code`\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $code = $paragraph->children()[0];
        self::assertInstanceOf(MdCode::class, $code);
        self::assertSpan(0, 6, $code);
    }
}
