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

use Alto\Rst\Node\DefinitionList;
use Alto\Rst\Node\DefinitionListItem;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Text;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefinitionList::class)]
#[CoversClass(DefinitionListItem::class)]
final class DefinitionListTest extends TestCase
{
    public function testChildrenPreserveTermClassifierAndDefinitionOrder(): void
    {
        $span = ByteSpan::of(0, 10);
        $term = new Text($span, 'term');
        $classifier = new Text($span, 'kind');
        $paragraph = new Paragraph($span, new Text($span, 'body'));
        $item = new DefinitionListItem($span, $term, [$classifier], [$paragraph]);
        $list = new DefinitionList($span, [$item]);

        self::assertSame([$item], $list->children());
        self::assertSame([$term, $classifier, $paragraph], $item->children());
        self::assertSame([$paragraph], $item->definition());
    }
}
