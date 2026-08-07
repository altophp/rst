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

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Node\Inline\InlineTarget;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InlineTarget::class)]
final class InlineTargetTest extends TestCase
{
    public function testConstruction(): void
    {
        $node = new InlineTarget(ByteSpan::of(3, 16), 'inline target');

        self::assertSame('inline target', $node->name);
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InlineTarget(ByteSpan::of(0, 3), '');
    }
}
