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
use Alto\Rst\Node\Table;
use Alto\Rst\Node\TableCell;
use Alto\Rst\Node\TableRow;
use Alto\Rst\Node\TableStyle;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Table::class)]
#[CoversClass(TableStyle::class)]
final class TableTest extends TestCase
{
    public function testConstruction(): void
    {
        $head = new TableRow(ByteSpan::of(13, 12), [new TableCell(ByteSpan::of(13, 5), [])]);
        $body = new TableRow(ByteSpan::of(39, 12), [new TableCell(ByteSpan::of(39, 5), [])]);
        $table = new Table(ByteSpan::of(0, 65), [$head], [$body], [5, 5], TableStyle::Simple);

        self::assertSame([$head], $table->head);
        self::assertSame([$body], $table->body);
        self::assertSame([5, 5], $table->columnWidths);
        self::assertSame(TableStyle::Simple, $table->style);
    }

    public function testChildrenAreHeadRowsThenBodyRows(): void
    {
        $head = new TableRow(ByteSpan::of(0, 1));
        $first = new TableRow(ByteSpan::of(1, 1));
        $second = new TableRow(ByteSpan::of(2, 1));
        $table = new Table(ByteSpan::of(0, 3), [$head], [$first, $second], [1], TableStyle::Simple);

        self::assertSame([$head, $first, $second], $table->children());
    }

    public function testStyleIsBackedByItsSourceSyntax(): void
    {
        self::assertSame('simple', TableStyle::Simple->value);
        self::assertSame('grid', TableStyle::Grid->value);
    }

    public function testNonPositiveColumnWidthIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Table(ByteSpan::of(0, 0), [], [], [5, 0], TableStyle::Simple);
    }
}
