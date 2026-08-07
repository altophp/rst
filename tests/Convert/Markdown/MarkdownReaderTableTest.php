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
use Alto\Rst\Convert\Markdown\MdParagraph;
use Alto\Rst\Convert\Markdown\MdTable;
use Alto\Rst\Convert\Markdown\MdTableAlignment;
use Alto\Rst\Convert\Markdown\MdTableCell;
use Alto\Rst\Convert\Markdown\MdTableRow;
use Alto\Rst\Convert\Markdown\MdText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(MarkdownReader::class)]
#[CoversClass(BlockDraft::class)]
#[CoversClass(DocumentDraft::class)]
#[CoversClass(MarkdownBlockParser::class)]
#[CoversClass(MarkdownInlineItem::class)]
#[CoversClass(MarkdownInlineParser::class)]
#[CoversClass(MdLineCursor::class)]
#[CoversClass(MdTable::class)]
#[CoversClass(MdTableRow::class)]
#[CoversClass(MdTableCell::class)]
#[CoversClass(MdTableAlignment::class)]
final class MarkdownReaderTableTest extends MarkdownReaderTestCase
{
    public function testSimpleTable(): void
    {
        $markdown = "| A | B |\n| - | - |\n| 1 | 2 |\n";
        $table = self::read($markdown)->children()[0];

        self::assertInstanceOf(MdTable::class, $table);
        self::assertCount(2, $table->header->children());
        self::assertCount(1, $table->rows());

        $headerCellText = $table->header->children()[0]->children()[0];
        self::assertInstanceOf(MdText::class, $headerCellText);
        self::assertSame('A', $headerCellText->text);

        $bodyCellText = $table->rows()[0]->children()[1]->children()[0];
        self::assertInstanceOf(MdText::class, $bodyCellText);
        self::assertSame('2', $bodyCellText->text);
    }

    public function testTableWithoutOuterPipes(): void
    {
        $markdown = "A | B\n- | -\n1 | 2\n";
        $table = self::read($markdown)->children()[0];
        self::assertInstanceOf(MdTable::class, $table);
        self::assertCount(2, $table->header->children());
    }

    #[DataProvider('alignments')]
    public function testColumnAlignment(string $delimiterCell, MdTableAlignment $expected): void
    {
        $markdown = "| A |\n| {$delimiterCell} |\n| 1 |\n";
        $table = self::read($markdown)->children()[0];
        self::assertInstanceOf(MdTable::class, $table);
        self::assertSame([$expected], $table->alignments);
    }

    /**
     * @return iterable<string, array{string, MdTableAlignment}>
     */
    public static function alignments(): iterable
    {
        yield 'none' => ['---', MdTableAlignment::None];
        yield 'left' => [':---', MdTableAlignment::Left];
        yield 'right' => ['---:', MdTableAlignment::Right];
        yield 'center' => [':---:', MdTableAlignment::Center];
    }

    public function testShortRowIsPaddedWithEmptyCells(): void
    {
        $markdown = "| A | B |\n| - | - |\n| 1 |\n";
        $table = self::read($markdown)->children()[0];
        self::assertInstanceOf(MdTable::class, $table);
        $row = $table->rows()[0];
        self::assertCount(2, $row->children());
        self::assertSame([], $row->children()[1]->children());
    }

    public function testLongRowIsTruncated(): void
    {
        $markdown = "| A |\n| - |\n| 1 | 2 | 3 |\n";
        $table = self::read($markdown)->children()[0];
        self::assertInstanceOf(MdTable::class, $table);
        self::assertCount(1, $table->rows()[0]->children());
    }

    public function testWithoutAMatchingDelimiterRowThereIsNoTable(): void
    {
        $node = self::read("| A | B |\nNot a delimiter row\n")->children()[0];
        self::assertNotInstanceOf(MdTable::class, $node);
        self::assertInstanceOf(MdParagraph::class, $node);
    }

    public function testTableEndsAtABlankLine(): void
    {
        $children = self::read("| A |\n| - |\n| 1 |\n\nAfter.\n")->children();
        self::assertCount(2, $children);
        self::assertInstanceOf(MdTable::class, $children[0]);
        self::assertInstanceOf(MdParagraph::class, $children[1]);
    }

    public function testEscapedPipeDoesNotSplitACell(): void
    {
        $markdown = "| A |\n| - |\n| one \\| two |\n";
        $table = self::read($markdown)->children()[0];
        self::assertInstanceOf(MdTable::class, $table);
        $cellText = $table->rows()[0]->children()[0]->children()[0];
        self::assertInstanceOf(MdText::class, $cellText);
        self::assertSame('one | two', $cellText->text);
    }

    public function testTableCellContentIsParsedInline(): void
    {
        $markdown = "| A |\n| - |\n| *emphasis* |\n";
        $table = self::read($markdown)->children()[0];
        self::assertInstanceOf(MdTable::class, $table);
        $cell = $table->rows()[0]->children()[0];
        self::assertCount(1, $cell->children());
    }

    public function testChildrenReturnsTheHeaderFollowedByEveryRow(): void
    {
        $markdown = "| A |\n| - |\n| 1 |\n| 2 |\n";
        $table = self::read($markdown)->children()[0];
        self::assertInstanceOf(MdTable::class, $table);
        self::assertSame([$table->header, ...$table->rows()], $table->children());
    }
}
