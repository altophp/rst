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

use Alto\Rst\Node\BulletList;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Table;
use Alto\Rst\Node\TableRow;
use Alto\Rst\Node\TableStyle;
use PHPUnit\Framework\Attributes\CoversNamespace;

#[CoversNamespace('Alto\\Rst\\Parser')]
final class GridTableParsingTest extends ParserTestCase
{
    public function testTwoColumnTableWithHead(): void
    {
        $rst = "+-------+-------+\n| Alpha | Beta  |\n+=======+=======+\n| one   | two   |\n+-------+-------+\n";
        $result = self::parseRst($rst);
        $table = self::tableOf($result);

        self::assertSame(TableStyle::Grid, $table->style);
        self::assertSame([7, 7], $table->columnWidths);
        self::assertSame([['Alpha', 'Beta']], self::rowTexts($table->head));
        self::assertSame([['one', 'two']], self::rowTexts($table->body));
        self::assertSpan(0, \strlen($rst) - 1, $table);
        self::assertNoProblems($result);
    }

    public function testTableWithoutHead(): void
    {
        $result = self::parseRst("+---+---+\n| a | b |\n+---+---+\n");
        $table = self::tableOf($result);

        self::assertSame([], $table->head);
        self::assertSame([['a', 'b']], self::rowTexts($table->body));
        self::assertNoProblems($result);
    }

    public function testHeaderOnlyTableReportsTheMissingBody(): void
    {
        $result = self::parseRst("+---+---+\n+===+===+\n");
        $table = self::tableOf($result);

        self::assertSame(TableStyle::Grid, $table->style);
        self::assertSame([], $table->body);
        self::assertContains('table/no-body', self::problemCodes($result));
    }

    public function testChangingCellBoundariesInsideARowReportsTheLine(): void
    {
        $result = self::parseRst(
            "+---+---+\n"
            ."| a | b |\n"
            ."| ab    |\n"
            ."+---+---+\n",
        );
        $table = self::tableOf($result);

        self::assertCount(1, $table->body);
        self::assertSame(['table/column-mismatch'], self::problemCodes($result));
    }

    public function testMultiLineCellCanHoldAList(): void
    {
        $result = self::parseRst(
            "+---+---------+\n"
            ."| A | - one   |\n"
            ."|   | - two   |\n"
            ."+---+---------+\n",
        );
        $table = self::tableOf($result);
        $cell = $table->body[0]->children()[1];

        self::assertCount(1, $cell->children());
        self::assertInstanceOf(BulletList::class, $cell->children()[0]);
        self::assertCount(2, $cell->children()[0]->children());
        self::assertNoProblems($result);
    }

    public function testMultiLineParagraphKeepsOnlyItsCellTextAndSourceSegments(): void
    {
        $rst = "+-----+-----+\n"
            ."| A   | B   |\n"
            ."+=====+=====+\n"
            ."| one | two |\n"
            ."| x   | y   |\n"
            ."+-----+-----+\n";
        $result = self::parseRst($rst);
        $table = self::tableOf($result);
        $paragraph = $table->body[0]->children()[0]->children()[0];

        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertSame("one\nx", $paragraph->text->text);
        self::assertSame(
            [
                [strpos($rst, 'one'), 3],
                [strpos($rst, 'x'), 1],
            ],
            array_map(
                static fn (\Alto\Rst\Source\ByteSpan $span): array => [$span->start, $span->length],
                $paragraph->text->sourceSegments(),
            ),
        );
        self::assertNoProblems($result);
    }

    public function testMissingVerticalRuleCreatesAColumnSpan(): void
    {
        $result = self::parseRst("+---+---+\n| span  |\n+---+---+\n");
        $table = self::tableOf($result);
        $cells = $table->body[0]->children();

        self::assertCount(1, $cells);
        self::assertSame(2, $cells[0]->colspan);
        self::assertSame('span', self::textOf($cells[0]->children()[0]));
        self::assertNoProblems($result);
    }

    public function testUnicodeContentUsesDisplayColumns(): void
    {
        $result = self::parseRst("+------+------+\n| Café | naïve|\n+------+------+\n");
        $table = self::tableOf($result);

        self::assertSame([['Café', 'naïve']], self::rowTexts($table->body));
        self::assertNoProblems($result);
    }

    public function testMissingBottomBorderDegradesToParagraph(): void
    {
        $result = self::parseRst("+---+---+\n| a | b |\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(Paragraph::class, $children[0]);
        self::assertSame(['table/malformed-border'], self::problemCodes($result));
    }

    private static function tableOf(\Alto\Rst\Parser\ParseResult $result): Table
    {
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(Table::class, $children[0]);

        return $children[0];
    }

    /**
     * @param list<TableRow> $rows
     *
     * @return list<list<string>>
     */
    private static function rowTexts(array $rows): array
    {
        return array_map(
            static fn (TableRow $row): array => array_map(
                static fn ($cell): string => [] === $cell->children() ? '' : self::textOf($cell->children()[0]),
                $row->children(),
            ),
            $rows,
        );
    }

    private static function textOf(object $node): string
    {
        self::assertInstanceOf(Paragraph::class, $node);

        return trim($node->text->text);
    }
}
