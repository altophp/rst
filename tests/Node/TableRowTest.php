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

use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\TableCell;
use Alto\Rst\Node\TableRow;
use Alto\Rst\Node\Text;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TableRow::class)]
final class TableRowTest extends TestCase
{
    public function testConstruction(): void
    {
        $cell = new TableCell(ByteSpan::of(0, 5), [
            new Paragraph(ByteSpan::of(0, 1), new Text(ByteSpan::of(0, 1), 'a')),
        ]);
        $row = new TableRow(ByteSpan::of(0, 12), [$cell]);

        self::assertSame(12, $row->span()->length);
        self::assertSame([$cell], $row->children());
    }

    public function testEmptyRow(): void
    {
        self::assertSame([], new TableRow(ByteSpan::of(0, 0))->children());
    }

    public function testDescendantsWalkCells(): void
    {
        $paragraph = new Paragraph(ByteSpan::of(0, 1), new Text(ByteSpan::of(0, 1), 'a'));
        $cell = new TableCell(ByteSpan::of(0, 5), [$paragraph]);
        $row = new TableRow(ByteSpan::of(0, 12), [$cell]);

        self::assertSame([$cell, $paragraph, $paragraph->text], iterator_to_array($row->descendants(), false));
    }
}
