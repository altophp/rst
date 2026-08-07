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

use Alto\Rst\Node\LiteralBlock;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LiteralBlock::class)]
final class LiteralBlockTest extends TestCase
{
    public function testConstruction(): void
    {
        $content = ByteSpan::between(14, 40);
        $block = new LiteralBlock(ByteSpan::between(10, 40), $content);

        self::assertSame(10, $block->span()->start);
        self::assertSame(40, $block->span()->end());
        self::assertSame($content, $block->content);
    }
}
