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
use Alto\Rst\Node\ContainerNode;
use Alto\Rst\Node\LiteralBlock;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Table;
use Alto\Rst\Node\TableCell;
use Alto\Rst\Node\TableRow;
use Alto\Rst\Node\TableStyle;
use Alto\Rst\Parser\ParseResult;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversNamespace('Alto\\Rst\\Parser')]
final class SimpleTableParsingTest extends ParserTestCase
{
    public function testTwoColumnTableWithHead(): void
    {
        $rst = "=====  =====\nAlpha  Beta\n=====  =====\none    two\nthree  four\n=====  =====\n";
        $result = self::parseRst($rst);
        $table = self::tableOf($result);

        self::assertSame(TableStyle::Simple, $table->style);
        self::assertSame([5, 5], $table->columnWidths);
        self::assertSame([['Alpha', 'Beta']], self::rowTexts($table->head));
        self::assertSame([['one', 'two'], ['three', 'four']], self::rowTexts($table->body));
        self::assertSpan(0, \strlen($rst) - 1, $table);
        self::assertNoProblems($result);
    }

    public function testTableWithoutHead(): void
    {
        $result = self::parseRst("=====  =====\none    two\nthree  four\n=====  =====\n");
        $table = self::tableOf($result);

        self::assertSame([], $table->head);
        self::assertSame([['one', 'two'], ['three', 'four']], self::rowTexts($table->body));
        self::assertNoProblems($result);
    }

    public function testColumnSpanUnderlineJoinsCells(): void
    {
        $rst = "=====  =====  ======\nName          Value\n------------  ------\n"
            ."First  Last   Number\n=====  =====  ======\nAda    Byron  1815\n=====  =====  ======\n";
        $result = self::parseRst($rst);
        $table = self::tableOf($result);

        self::assertSame([5, 5, 6], $table->columnWidths);
        self::assertSame([['Name', 'Value'], ['First', 'Last', 'Number']], self::rowTexts($table->head));
        self::assertSame([['Ada', 'Byron', '1815']], self::rowTexts($table->body));

        self::assertSame([2, 1], self::colspansOf($table->head[0]));
        self::assertSame([1, 1, 1], self::colspansOf($table->head[1]));
        self::assertNoProblems($result);
    }

    public function testMultiLineCellYieldsOneParagraph(): void
    {
        $rst = "=====  ===========\nKey    Description\n=====  ===========\n"
            ."first  a value that\n       wraps onto a\n       second line\nlast   short\n=====  ===========\n";
        $result = self::parseRst($rst);
        $table = self::tableOf($result);

        self::assertSame([5, 11], $table->columnWidths);
        self::assertCount(2, $table->body);

        $cell = $table->body[0]->children()[1];
        self::assertCount(1, $cell->children());
        self::assertSame("a value that\n       wraps onto a\n       second line", self::textOf($cell->children()[0]));
        self::assertNoProblems($result);
    }

    public function testEmptyFirstCellContinuesThePreviousRow(): void
    {
        $result = self::parseRst("=====  =====\nAlpha  Beta\n=====  =====\none\n       two\nthree  four\n=====  =====\n");
        $table = self::tableOf($result);

        self::assertSame([['one', 'two'], ['three', 'four']], self::rowTexts($table->body));
        self::assertNoProblems($result);
    }

    public function testLastColumnOverflowsItsBorder(): void
    {
        $result = self::parseRst(
            "=====  =====\nAlpha  Beta\n=====  =====\none    a much longer value than the border\ntwo    short\n=====  =====\n",
        );
        $table = self::tableOf($result);

        self::assertSame([5, 5], $table->columnWidths);
        self::assertSame(
            [['one', 'a much longer value than the border'], ['two', 'short']],
            self::rowTexts($table->body),
        );
        self::assertNoProblems($result);
    }

    public function testBlankLineInsideACellStartsASecondParagraph(): void
    {
        $result = self::parseRst(
            "=====  ==========\nA      B\n=====  ==========\n1      first para\n\n       second para\n2      x\n=====  ==========\n",
        );
        $table = self::tableOf($result);

        self::assertCount(2, $table->body);

        $cell = $table->body[0]->children()[1];
        self::assertSame(['first para', 'second para'], array_map(self::textOf(...), $cell->children()));
        self::assertNoProblems($result);
    }

    public function testCellCanHoldABulletList(): void
    {
        $result = self::parseRst("=====  ==========\nA      B\n=====  ==========\n1      - one\n       - two\n=====  ==========\n");
        $table = self::tableOf($result);

        $cell = $table->body[0]->children()[1];
        $list = $cell->children()[0];
        self::assertInstanceOf(BulletList::class, $list);
        self::assertCount(2, $list->children());
        self::assertNoProblems($result);
    }

    public function testCellCanHoldALiteralBlock(): void
    {
        $rst = "=====  ==========\nA      B\n=====  ==========\n1      text::\n\n         code\n=====  ==========\n";
        $result = self::parseRst($rst);
        $table = self::tableOf($result);

        $cell = $table->body[0]->children()[1];
        self::assertCount(2, $cell->children());
        self::assertInstanceOf(Paragraph::class, $cell->children()[0]);

        $literal = $cell->children()[1];
        self::assertInstanceOf(LiteralBlock::class, $literal);
        self::assertSame('  code', Source::fromString($rst)->slice($literal->content));
        self::assertNoProblems($result);
    }

    public function testIndentedTableInsideAListItem(): void
    {
        $result = self::parseRst("- item:\n\n  =====  =====\n  a      b\n  =====  =====\n\n- next\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $list = $children[0];
        self::assertInstanceOf(BulletList::class, $list);

        $table = $list->children()[0]->children()[1];
        self::assertInstanceOf(Table::class, $table);
        self::assertSame([['a', 'b']], self::rowTexts($table->body));
        self::assertNoProblems($result);
    }

    public function testCellsAreCutOnCodePointsNotBytes(): void
    {
        $result = self::parseRst("=====  =====\nCafé   Beta\n=====  =====\nnaïve  ok\n=====  =====\n");
        $table = self::tableOf($result);

        self::assertSame([['Café', 'Beta']], self::rowTexts($table->head));
        self::assertSame([['naïve', 'ok']], self::rowTexts($table->body));
        self::assertNoProblems($result);
    }

    public function testShortLinesYieldEmptyTrailingCells(): void
    {
        $result = self::parseRst("=====  =====\nA      B\n=====  =====\none\n=====  =====\n");
        $table = self::tableOf($result);

        $cells = $table->body[0]->children();
        self::assertCount(2, $cells);
        self::assertSame([], $cells[1]->children());
        self::assertNoProblems($result);
    }

    public function testMissingBottomBorderDegradesToParagraph(): void
    {
        $result = self::parseRst("=====  =====\na      b\n\nnext paragraph\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(Paragraph::class, $children[0]);
        self::assertSame("=====  =====\na      b", self::textOf($children[0]));
        self::assertInstanceOf(Paragraph::class, $children[1]);
        self::assertSame(['table/malformed-border'], self::problemCodes($result));
    }

    public function testMismatchedBottomBorderKeepsTheTopBorderLayout(): void
    {
        $result = self::parseRst("=====  =====\na      b\n=====  ======\n");
        $table = self::tableOf($result);

        self::assertSame([5, 5], $table->columnWidths);
        self::assertSame([['a', 'b']], self::rowTexts($table->body));
        self::assertSame(['table/malformed-border', 'table/column-mismatch'], self::problemCodes($result));
    }

    public function testTextInAColumnMarginIsReported(): void
    {
        $result = self::parseRst("=====  =====\naaaaaaa  b\n=====  =====\n");
        $table = self::tableOf($result);

        self::assertSame([['aaaaaaa', 'b']], self::rowTexts($table->body));
        self::assertSame(['table/text-in-column-margin'], self::problemCodes($result));
    }

    #[DataProvider('misalignedSpanUnderlines')]
    public function testSpanUnderlineThatDoesNotLineUpIsReported(string $rst): void
    {
        $result = self::parseRst($rst);
        $table = self::tableOf($result);

        self::assertSame(['table/column-mismatch'], self::problemCodes($result));

        foreach ($table->body as $row) {
            self::assertSame([1, 1], array_slice(self::colspansOf($row), 0, 2));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function misalignedSpanUnderlines(): iterable
    {
        yield 'underline stops short of the last column' => [
            "=====  =====\na      b\n----\nc      d\n=====  =====\n",
        ];

        yield 'underline run ends inside a column' => [
            "=====  =====  =====\na      b      c\n-----  ----  ------\nd      e      f\n=====  =====  =====\n",
        ];

        yield 'underline run starts inside a margin' => [
            "===  ===  ===  ===\na    b    c    d\n---   --  ---  ---\ne    f    g    h\n===  ===  ===  ===\n",
        ];
    }

    public function testLineWithAnEmptyFirstCellBeforeAnyRowIsDropped(): void
    {
        $result = self::parseRst("=====  =====\n       x\na      b\n=====  =====\n");
        $table = self::tableOf($result);

        self::assertSame([['a', 'b']], self::rowTexts($table->body));
        self::assertNoProblems($result);
    }

    public function testHeadSeparatorWithoutFollowingRowsLeavesEveryRowInTheBody(): void
    {
        $result = self::parseRst("=====  =====\nh1     h2\n=====  =====\n=====  =====\n");
        $table = self::tableOf($result);

        self::assertSame([], $table->head);
        self::assertSame([['h1', 'h2']], self::rowTexts($table->body));
        self::assertNoProblems($result);
    }

    public function testTableWithoutRowsIsReported(): void
    {
        $result = self::parseRst("=====  =====\n=====  =====\n");
        $table = self::tableOf($result);

        self::assertSame([], $table->head);
        self::assertSame([], $table->body);
        self::assertSame(['table/no-body'], self::problemCodes($result));
    }

    public function testBorderFollowedByABlankLineEndsTheTable(): void
    {
        $result = self::parseRst("=====  =====\nh1     h2\n=====  =====\n\nafter\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        $table = $children[0];
        self::assertInstanceOf(Table::class, $table);
        self::assertSame([], $table->head);
        self::assertSame([['h1', 'h2']], self::rowTexts($table->body));
        self::assertInstanceOf(Paragraph::class, $children[1]);
        self::assertNoProblems($result);
    }

    public function testRowAndCellSpansAddressTheSourceCells(): void
    {
        $rst = "=====  =====\none    two\n=====  =====\n";
        $result = self::parseRst($rst);
        $table = self::tableOf($result);

        $row = $table->body[0];
        self::assertSpan(13, 23, $row);

        $cells = $row->children();
        self::assertSpan(13, 16, $cells[0]);
        self::assertSpan(20, 23, $cells[1]);
        self::assertNoProblems($result);
    }

    public function testTableAfterAParagraphWithoutABlankLineStaysText(): void
    {
        $result = self::parseRst("some text\n=====  =====\na      b\n=====  =====\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(Paragraph::class, $children[0]);
        self::assertNoProblems($result);
    }

    public function testConformanceTableFixturesParseWithoutProblems(): void
    {
        $files = glob(__DIR__.'/../fixtures/conformance/tables/*.rst');
        self::assertIsArray($files);
        self::assertNotSame([], $files);

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            self::assertIsString($contents);

            $result = self::parseRst($contents);
            $children = $result->document()->children();

            self::assertCount(1, $children, basename($file));
            self::assertInstanceOf(Table::class, $children[0], basename($file));
            self::assertSame([], self::problemCodes($result), basename($file));
        }
    }

    /**
     * A cell is a rectangle, a ByteSpan is a single range, so a cell whose
     * rows interleave text across lines necessarily encloses its
     * neighbours' bytes. Well-formed tables must stay free of that, because
     * the editing layer relies on sibling subtrees not overlapping.
     */
    public function testWellFormedTablesHaveNoOverlappingSiblingSpans(): void
    {
        $files = glob(__DIR__.'/../fixtures/conformance/tables/*.rst');
        self::assertIsArray($files);
        self::assertNotSame([], $files);

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            self::assertIsString($contents);

            self::assertSame(
                [],
                self::siblingOverlaps(self::parseRst($contents)->document()),
                basename($file),
            );
        }
    }

    public function testInterleavedRowsOverlapSiblingCellSpans(): void
    {
        $result = self::parseRst("=====  =====  =====\na      b      c\n       bb     cc\n=====  =====  =====\n");

        self::assertSame(['TableCell[27,45) overlaps TableCell[34,52)'], self::siblingOverlaps($result->document()));
        self::assertNoProblems($result);
    }

    /**
     * @return list<string>
     */
    private static function siblingOverlaps(Node $node): array
    {
        if (!$node instanceof ContainerNode) {
            return [];
        }

        $overlaps = [];
        $children = $node->children();

        foreach ($children as $index => $first) {
            foreach (\array_slice($children, $index + 1) as $second) {
                $a = $first->span();
                $b = $second->span();

                if (0 !== $a->length && 0 !== $b->length && $a->end() > $b->start && $b->end() > $a->start) {
                    $overlaps[] = \sprintf(
                        '%s[%d,%d) overlaps %s[%d,%d)',
                        self::shortName($first), $a->start, $a->end(),
                        self::shortName($second), $b->start, $b->end(),
                    );
                }
            }
        }

        foreach ($children as $child) {
            foreach (self::siblingOverlaps($child) as $nested) {
                $overlaps[] = $nested;
            }
        }

        return $overlaps;
    }

    private static function shortName(Node $node): string
    {
        $class = $node::class;

        return false === ($position = strrpos($class, '\\')) ? $class : substr($class, $position + 1);
    }

    private static function tableOf(ParseResult $result): Table
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
        $texts = [];

        foreach ($rows as $row) {
            $cells = [];

            foreach ($row->children() as $cell) {
                $cells[] = implode("\n", array_map(self::textOf(...), $cell->children()));
            }

            $texts[] = $cells;
        }

        return $texts;
    }

    /**
     * @return list<int>
     */
    private static function colspansOf(TableRow $row): array
    {
        return array_map(static fn (TableCell $cell): int => $cell->colspan, $row->children());
    }

    private static function textOf(Node $node): string
    {
        self::assertInstanceOf(Paragraph::class, $node);

        return $node->text->text;
    }
}
