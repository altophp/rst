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
use Alto\Rst\Node\BulletList;
use Alto\Rst\Node\ListItem;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Text;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BulletList::class)]
final class BulletListTest extends TestCase
{
    public function testConstruction(): void
    {
        $item = new ListItem(ByteSpan::of(0, 3), [
            new Paragraph(ByteSpan::of(2, 1), new Text(ByteSpan::of(2, 1), 'a')),
        ]);
        $list = new BulletList(ByteSpan::of(0, 3), '*', [$item]);

        self::assertSame(0, $list->span()->start);
        self::assertSame('*', $list->marker);
        self::assertSame([$item], $list->children());
    }

    public function testEmptyMarkerIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BulletList(ByteSpan::of(0, 0), '', []);
    }
}
