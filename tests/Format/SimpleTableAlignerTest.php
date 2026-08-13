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

namespace Alto\Rst\Tests\Format;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Format\SimpleTableAligner;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Table;
use Alto\Rst\Node\TableCell;
use Alto\Rst\Node\TableRow;
use Alto\Rst\Node\TableStyle;
use Alto\Rst\Node\Text;
use Alto\Rst\Operation\SourcePatchApplier;
use Alto\Rst\Parser\ParseResult;
use Alto\Rst\Problem\ProblemReport;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Rst;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SimpleTableAligner::class)]
final class SimpleTableAlignerTest extends TestCase
{
    #[DataProvider('lineEndings')]
    public function testAlignsAStableTableAndPreservesLineEndings(string $eol): void
    {
        $source = '==========  ====================' . $eol
            . 'Name        Value' . $eol
            . '==========  ====================' . $eol
            . 'A           One' . $eol
            . 'Long        Two' . $eol
            . '==========  ====================' . $eol;
        $formatted = self::apply($source);

        self::assertSame(
            '====  =====' . $eol
            . 'Name  Value' . $eol
            . '====  =====' . $eol
            . 'A     One' . $eol
            . 'Long  Two' . $eol
            . '====  =====' . $eol,
            $formatted,
        );
        self::assertFalse(Rst::docutils()->parse($formatted)->problems()->hasProblems());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
        yield 'bare CR' => ["\r"];
    }

    public function testAlignsATableWithoutAHeaderOrFinalLineEnding(): void
    {
        $source = <<<'RST'
            ==========  ==========
            first       one
            longer      two
            ==========  ==========
            RST;

        self::assertSame(
            <<<'RST'
                ======  ===
                first   one
                longer  two
                ======  ===
                RST,
            self::apply($source),
        );
    }

    public function testAlignsAnIndentedTableInsideAListItem(): void
    {
        $source = <<<'RST'
            - table:

              ==========  ==========
              first       one
              longer      two
              ==========  ==========

            - next
            RST;
        $formatted = self::apply($source);

        self::assertStringContainsString(
            "  ======  ===\n"
            . "  first   one\n"
            . "  longer  two\n"
            . '  ======  ===',
            $formatted,
        );
        self::assertFalse(Rst::docutils()->parse($formatted)->problems()->hasProblems());
    }

    public function testLeavesWideCharactersUntouchedWhenDisplayAndParserColumnsDiffer(): void
    {
        if (!\function_exists('mb_strwidth')) {
            self::markTestSkipped('Unicode display width requires mbstring.');
        }

        $source = "==========  ==========\n文档          Value\n==========  ==========\nRésumé      one\n==========  ==========\n";

        self::assertSame([], self::patches($source));
    }

    public function testAWideTableDoesNotBlockAnIndependentAsciiTable(): void
    {
        if (!\function_exists('mb_strwidth')) {
            self::markTestSkipped('Unicode display width requires mbstring.');
        }

        $source = "==========  ==========\n文档          Value\n==========  ==========\nRésumé      one\n==========  ==========\n\n"
            . "==========  ==========\nfirst       one\nlonger      two\n==========  ==========\n";
        $formatted = self::apply($source);

        self::assertStringStartsWith("==========  ==========\n文档", $formatted);
        self::assertStringEndsWith("======  ===\nfirst   one\nlonger  two\n======  ===\n", $formatted);
    }

    #[DataProvider('unstableTables')]
    public function testLeavesUnstableOrComplexTablesUntouched(string $source): void
    {
        self::assertSame([], self::patches($source));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unstableTables(): iterable
    {
        yield 'multi-line cell' => [
            "=====  ===========\nKey    Description\n=====  ===========\n"
            . "first  a value that\n       wraps again\n=====  ===========\n",
        ];
        yield 'column span' => [
            "=====  =====  ======\nName          Value\n------------  ------\n"
            . "First  Last   Number\n=====  =====  ======\nAda    Byron  1815\n=====  =====  ======\n",
        ];
        yield 'grid table' => [
            "+------+------+\n| one  | two  |\n+------+------+\n",
        ];
        yield 'empty cell' => [
            "=====  =====\none\n=====  =====\n",
        ];
        yield 'parser recovery elsewhere' => [
            ":author: unsupported field\n\n=====  =====\none    two\n=====  =====\n",
        ];
    }

    public function testAlignmentIsIdempotent(): void
    {
        $source = "==========  ==========\nfirst       one\nlonger      two\n==========  ==========\n";
        $formatted = self::apply($source);

        self::assertSame([], self::patches($formatted));
    }

    public function testRejectsAParseResultFromAnotherSource(): void
    {
        $source = Source::fromString("=====  =====\none    two\n=====  =====\n");
        $result = Rst::docutils()->parse("=====  =====\na      b\n=====  =====\n");

        $this->expectException(InvalidArgumentException::class);

        new SimpleTableAligner()->patches($result, $source);
    }

    public function testManualSingleColumnTableIsNotAligned(): void
    {
        $source = Source::fromString('a');
        $cell = self::cell(ByteSpan::of(0, 1), 'a');
        $table = new Table(
            ByteSpan::of(0, 1),
            [],
            [new TableRow(ByteSpan::of(0, 1), [$cell])],
            [1],
            TableStyle::Simple,
        );

        self::assertSame([], new SimpleTableAligner()->patches(self::parseResult($source, $table), $source));
    }

    public function testManualTableRejectsInvalidUtf8AndUnstableCellBytes(): void
    {
        $invalidSource = Source::fromString("\xFF b");
        $invalid = new Table(
            ByteSpan::of(0, 3),
            [],
            [new TableRow(ByteSpan::of(0, 3), [
                self::cell(ByteSpan::of(0, 1), "\xFF"),
                self::cell(ByteSpan::of(2, 1), 'b'),
            ])],
            [1, 1],
            TableStyle::Simple,
        );
        self::assertSame([], new SimpleTableAligner()->patches(self::parseResult($invalidSource, $invalid), $invalidSource));

        $spacedSource = Source::fromString(' a b');
        $spaced = new Table(
            ByteSpan::of(0, 4),
            [],
            [new TableRow(ByteSpan::of(0, 4), [
                self::cell(ByteSpan::of(0, 2), ' a'),
                self::cell(ByteSpan::of(3, 1), 'b'),
            ])],
            [2, 1],
            TableStyle::Simple,
        );
        self::assertSame([], new SimpleTableAligner()->patches(self::parseResult($spacedSource, $spaced), $spacedSource));
    }

    public function testManualTableRejectsMissingBordersAndMismatchedSpan(): void
    {
        $shortSource = Source::fromString('a b');
        $short = self::twoCellTable($shortSource, ByteSpan::of(0, 3), 0, 2);
        self::assertSame([], new SimpleTableAligner()->patches(self::parseResult($shortSource, $short), $shortSource));

        $spannedSource = Source::fromString("===\na b\n===");
        $spanned = self::twoCellTable($spannedSource, ByteSpan::of(1, 10), 4, 6);
        self::assertSame([], new SimpleTableAligner()->patches(self::parseResult($spannedSource, $spanned), $spannedSource));
    }

    public function testManualTableWithTabIndentIsNotRealigned(): void
    {
        $source = Source::fromString("\t=== ===\n\ta   b\n\t=== ===");
        $left = strpos($source->bytes, 'a');
        $right = strpos($source->bytes, 'b');
        self::assertIsInt($left);
        self::assertIsInt($right);
        $table = self::twoCellTable(
            $source,
            ByteSpan::of(0, \strlen($source->bytes)),
            $left,
            $right,
        );

        self::assertSame([], new SimpleTableAligner()->patches(self::parseResult($source, $table), $source));
    }

    /**
     * @return list<\Alto\Rst\Operation\SourcePatch>
     */
    private static function patches(string $bytes): array
    {
        $source = Source::fromString($bytes);
        $result = Rst::docutils()->parse($bytes);

        return new SimpleTableAligner()->patches($result, $source, Profile::docutils());
    }

    private static function apply(string $bytes): string
    {
        return new SourcePatchApplier()->apply($bytes, self::patches($bytes))->bytes;
    }

    private static function cell(ByteSpan $span, string $text): TableCell
    {
        return new TableCell($span, [new Paragraph($span, new Text($span, $text))]);
    }

    private static function twoCellTable(Source $source, ByteSpan $tableSpan, int $left, int $right): Table
    {
        $rowSpan = ByteSpan::between($left, $right + 1);

        return new Table(
            $tableSpan,
            [],
            [new TableRow($rowSpan, [
                self::cell(ByteSpan::of($left, 1), $source->slice(ByteSpan::of($left, 1))),
                self::cell(ByteSpan::of($right, 1), $source->slice(ByteSpan::of($right, 1))),
            ])],
            [1, 1],
            TableStyle::Simple,
        );
    }

    private static function parseResult(Source $source, Table $table): ParseResult
    {
        return new ParseResult(
            new Document(ByteSpan::of(0, \strlen($source->bytes)), [$table]),
            new ProblemReport(),
            $source,
            Profile::docutils(),
        );
    }
}
