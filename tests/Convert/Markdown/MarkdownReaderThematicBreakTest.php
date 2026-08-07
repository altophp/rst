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
use Alto\Rst\Convert\Markdown\MdLineCursor;
use Alto\Rst\Convert\Markdown\MdList;
use Alto\Rst\Convert\Markdown\MdParagraph;
use Alto\Rst\Convert\Markdown\MdThematicBreak;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(MarkdownReader::class)]
#[CoversClass(BlockDraft::class)]
#[CoversClass(DocumentDraft::class)]
#[CoversClass(MarkdownBlockParser::class)]
#[CoversClass(MarkdownInlineItem::class)]
#[CoversClass(MarkdownInlineParser::class)]
#[CoversClass(MdLineCursor::class)]
#[CoversClass(MdThematicBreak::class)]
final class MarkdownReaderThematicBreakTest extends MarkdownReaderTestCase
{
    #[DataProvider('breaks')]
    public function testThematicBreak(string $markdown, string $expectedMarker): void
    {
        $node = self::read($markdown)->children()[0];
        self::assertInstanceOf(MdThematicBreak::class, $node);
        self::assertSame($expectedMarker, $node->marker);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function breaks(): iterable
    {
        yield 'three hyphens' => ["---\n", '-'];
        yield 'three asterisks' => ["***\n", '*'];
        yield 'three underscores' => ["___\n", '_'];
        yield 'many hyphens' => ["-----\n", '-'];
        yield 'spaced hyphens' => ["- - -\n", '-'];
        yield 'spaced asterisks' => ["* * *\n", '*'];
    }

    public function testTwoHyphensIsNotAThematicBreak(): void
    {
        $node = self::read("--\n")->children()[0];
        self::assertNotInstanceOf(MdThematicBreak::class, $node);
    }

    public function testThematicBreakSeparatesParagraphs(): void
    {
        $children = self::read("Before.\n\n---\n\nAfter.\n")->children();
        self::assertCount(3, $children);
        self::assertInstanceOf(MdParagraph::class, $children[0]);
        self::assertInstanceOf(MdThematicBreak::class, $children[1]);
        self::assertInstanceOf(MdParagraph::class, $children[2]);
    }

    public function testThematicBreakSpan(): void
    {
        $node = self::read("---\n")->children()[0];
        self::assertSpan(0, 3, $node);
    }

    public function testSingleDashAfterParagraphIsASetextUnderlineNotAThematicBreak(): void
    {
        $node = self::read("Heading\n-\n")->children()[0];
        self::assertNotInstanceOf(MdThematicBreak::class, $node);
        self::assertNotInstanceOf(MdList::class, $node);
    }

    public function testAsteriskThematicBreakInterruptsAnOpenParagraph(): void
    {
        $children = self::read("Paragraph\n***\n")->children();
        self::assertCount(2, $children);
        self::assertInstanceOf(MdParagraph::class, $children[0]);
        self::assertInstanceOf(MdThematicBreak::class, $children[1]);
    }
}
