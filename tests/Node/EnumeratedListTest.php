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
use Alto\Rst\Node\EnumeratedList;
use Alto\Rst\Node\EnumerationStyle;
use Alto\Rst\Node\ListItem;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Text;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnumeratedList::class)]
#[CoversClass(EnumerationStyle::class)]
final class EnumeratedListTest extends TestCase
{
    public function testConstruction(): void
    {
        $item = new ListItem(ByteSpan::of(0, 4), [
            new Paragraph(ByteSpan::of(3, 1), new Text(ByteSpan::of(3, 1), 'a')),
        ]);
        $list = new EnumeratedList(ByteSpan::of(0, 4), EnumerationStyle::Arabic, 3, [$item]);

        self::assertSame(0, $list->span()->start);
        self::assertSame(EnumerationStyle::Arabic, $list->style);
        self::assertSame(3, $list->start);
        self::assertSame([$item], $list->children());
    }

    public function testNegativeStartIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EnumeratedList(ByteSpan::of(0, 0), EnumerationStyle::Arabic, -1, []);
    }

    public function testStylesMirrorDocutilsEnumTypes(): void
    {
        self::assertSame(EnumerationStyle::Arabic, EnumerationStyle::from('arabic'));
        self::assertSame(EnumerationStyle::LowerAlpha, EnumerationStyle::from('loweralpha'));
        self::assertSame(EnumerationStyle::UpperAlpha, EnumerationStyle::from('upperalpha'));
        self::assertSame(EnumerationStyle::LowerRoman, EnumerationStyle::from('lowerroman'));
        self::assertSame(EnumerationStyle::UpperRoman, EnumerationStyle::from('upperroman'));
    }
}
