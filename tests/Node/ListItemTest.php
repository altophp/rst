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

use Alto\Rst\Node\ListItem;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Text;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListItem::class)]
final class ListItemTest extends TestCase
{
    public function testConstruction(): void
    {
        $paragraph = new Paragraph(ByteSpan::of(2, 1), new Text(ByteSpan::of(2, 1), 'a'));
        $item = new ListItem(ByteSpan::of(0, 3), [$paragraph]);

        self::assertSame(0, $item->span()->start);
        self::assertSame([$paragraph], $item->children());
    }

    public function testEmptyItem(): void
    {
        $item = new ListItem(ByteSpan::of(0, 2));

        self::assertSame([], $item->children());
    }
}
