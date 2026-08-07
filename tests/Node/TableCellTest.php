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

namespace Alto\Rst\Tests\Node;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\TableCell;
use Alto\Rst\Node\Text;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TableCell::class)]
final class TableCellTest extends TestCase
{
    public function testConstruction(): void
    {
        $paragraph = new Paragraph(ByteSpan::of(0, 1), new Text(ByteSpan::of(0, 1), 'a'));
        $cell = new TableCell(ByteSpan::of(0, 5), [$paragraph]);

        self::assertSame(0, $cell->span()->start);
        self::assertSame([$paragraph], $cell->children());
        self::assertSame(1, $cell->colspan);
        self::assertSame(1, $cell->rowspan);
    }

    public function testSpansAreCounts(): void
    {
        $cell = new TableCell(ByteSpan::of(0, 5), [], 3, 2);

        self::assertSame(3, $cell->colspan);
        self::assertSame(2, $cell->rowspan);
    }

    #[DataProvider('invalidSpans')]
    public function testSpansBelowOneAreRejected(int $colspan, int $rowspan): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TableCell(ByteSpan::of(0, 0), [], $colspan, $rowspan);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidSpans(): iterable
    {
        yield 'zero colspan' => [0, 1];
        yield 'negative colspan' => [-1, 1];
        yield 'zero rowspan' => [1, 0];
        yield 'negative rowspan' => [1, -2];
    }
}
