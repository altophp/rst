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

namespace Alto\Rst\Tests\Node\Inline;

use Alto\Rst\Node\Inline\InlineText;
use Alto\Rst\Node\Inline\Strong;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Strong::class)]
final class StrongTest extends TestCase
{
    public function testConstruction(): void
    {
        $child = new InlineText(ByteSpan::of(2, 4), 'text');
        $strong = new Strong(ByteSpan::of(0, 8), [$child]);

        self::assertSame(8, $strong->span()->end());
        self::assertSame([$child], $strong->children);
        self::assertSame([$child], $strong->children());
    }

    public function testEmptyByDefault(): void
    {
        self::assertSame([], new Strong(ByteSpan::of(0, 4))->children());
    }
}
