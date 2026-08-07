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

use Alto\Rst\Node\Inline\Emphasis;
use Alto\Rst\Node\Inline\InlineText;
use Alto\Rst\Node\Inline\Strong;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Emphasis::class)]
final class EmphasisTest extends TestCase
{
    public function testConstruction(): void
    {
        $child = new InlineText(ByteSpan::of(1, 4), 'text');
        $emphasis = new Emphasis(ByteSpan::of(0, 6), [$child]);

        self::assertSame(0, $emphasis->span()->start);
        self::assertSame([$child], $emphasis->children);
        self::assertSame([$child], $emphasis->children());
    }

    public function testEmptyByDefault(): void
    {
        self::assertSame([], new Emphasis(ByteSpan::of(0, 2))->children());
    }

    public function testDescendantsWalkNestedChildren(): void
    {
        $inner = new InlineText(ByteSpan::of(3, 4), 'text');
        $strong = new Strong(ByteSpan::of(1, 8), [$inner]);
        $emphasis = new Emphasis(ByteSpan::of(0, 10), [$strong]);

        self::assertSame([$strong, $inner], iterator_to_array($emphasis->descendants(), false));
    }
}
