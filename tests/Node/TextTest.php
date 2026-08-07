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
use Alto\Rst\Node\Text;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Text::class)]
final class TextTest extends TestCase
{
    public function testConstruction(): void
    {
        $text = new Text(ByteSpan::of(7, 5), 'Hello');

        self::assertSame(7, $text->span()->start);
        self::assertSame(5, $text->span()->length);
        self::assertSame('Hello', $text->text);
        self::assertSame([$text->span()], $text->sourceSegments());
    }

    public function testConstructionWithNonContiguousSourceSegments(): void
    {
        $segments = [ByteSpan::of(7, 5), ByteSpan::of(20, 5)];
        $text = new Text(ByteSpan::between(7, 25), "Hello\nworld", $segments);

        self::assertSame("Hello\nworld", $text->text);
        self::assertSame($segments, $text->sourceSegments());
    }

    public function testSourceSegmentsMustMatchLogicalLines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Text source segments must match its logical line count.');

        new Text(ByteSpan::between(7, 25), "Hello\nworld", [ByteSpan::of(7, 5)]);
    }

    public function testSourceSegmentLengthsMustMatchTheirLogicalLines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lengths must match');

        new Text(ByteSpan::of(0, 5), 'Hello', [ByteSpan::of(0, 4)]);
    }

    public function testSourceSegmentsMustStayInsideTheTextSpan(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stay within its span');

        new Text(ByteSpan::of(2, 5), 'Hello', [ByteSpan::of(1, 5)]);
    }

    public function testSourceSegmentsMustBeOrderedAndNonOverlapping(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ordered and non-overlapping');

        new Text(
            ByteSpan::of(0, 8),
            "abc\ndef",
            [ByteSpan::of(3, 3), ByteSpan::of(2, 3)],
        );
    }

    public function testEmptyText(): void
    {
        $text = new Text(ByteSpan::of(7, 0), '');

        self::assertSame('', $text->text);
        self::assertTrue($text->span()->isEmpty());
    }
}
