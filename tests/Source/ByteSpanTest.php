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

namespace Alto\Rst\Tests\Source;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ByteSpan::class)]
final class ByteSpanTest extends TestCase
{
    public function testOf(): void
    {
        $span = ByteSpan::of(4, 6);

        self::assertSame(4, $span->start);
        self::assertSame(6, $span->length);
        self::assertSame(10, $span->end());
        self::assertFalse($span->isEmpty());
    }

    public function testBetween(): void
    {
        $span = ByteSpan::between(4, 10);

        self::assertSame(4, $span->start);
        self::assertSame(6, $span->length);
    }

    public function testEmptySpan(): void
    {
        $span = ByteSpan::of(4, 0);

        self::assertTrue($span->isEmpty());
        self::assertSame(4, $span->end());
    }

    public function testContainsIsHalfOpen(): void
    {
        $span = ByteSpan::between(4, 10);

        self::assertFalse($span->contains(3));
        self::assertTrue($span->contains(4));
        self::assertTrue($span->contains(9));
        self::assertFalse($span->contains(10));
    }

    public function testUnion(): void
    {
        $union = ByteSpan::between(4, 10)->union(ByteSpan::between(8, 14));

        self::assertSame(4, $union->start);
        self::assertSame(14, $union->end());
    }

    public function testNegativeStartIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ByteSpan::of(-1, 3);
    }

    public function testNegativeLengthIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ByteSpan::of(0, -1);
    }

    public function testInvertedBetweenIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ByteSpan::between(10, 4);
    }
}
