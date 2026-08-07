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
use Alto\Rst\Source\Line;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Source::class)]
final class SourceTest extends TestCase
{
    public function testEmptyInput(): void
    {
        $source = Source::fromString('');

        self::assertSame('', $source->bytes);
        self::assertSame(0, $source->lineCount());
        self::assertSame([], $source->lines());
        self::assertFalse($source->hasBom());
    }

    public function testSingleLineWithoutTrailingNewline(): void
    {
        $source = Source::fromString('hello');

        self::assertSame(1, $source->lineCount());

        $line = $source->line(0);

        self::assertSame(0, $line->span->start);
        self::assertSame(5, $line->span->length);
        self::assertSame('', $line->terminator);
        self::assertSame('hello', $source->slice($line->span));
    }

    public function testSingleLineWithTrailingNewline(): void
    {
        $source = Source::fromString("hello\n");

        self::assertSame(1, $source->lineCount());
        self::assertSame("\n", $source->line(0)->terminator);
        self::assertSame('hello', $source->slice($source->line(0)->span));
    }

    public function testMixedLineEndings(): void
    {
        $source = Source::fromString("a\nb\r\nc\rd");

        self::assertSame(4, $source->lineCount());

        self::assertSame('a', $source->slice($source->line(0)->span));
        self::assertSame("\n", $source->line(0)->terminator);

        self::assertSame('b', $source->slice($source->line(1)->span));
        self::assertSame("\r\n", $source->line(1)->terminator);

        self::assertSame('c', $source->slice($source->line(2)->span));
        self::assertSame("\r", $source->line(2)->terminator);

        self::assertSame('d', $source->slice($source->line(3)->span));
        self::assertSame('', $source->line(3)->terminator);
    }

    public function testLineSpansCoverOriginalBytes(): void
    {
        $bytes = "a\nb\r\nc\rd";
        $source = Source::fromString($bytes);

        $rebuilt = '';
        foreach ($source->lines() as $line) {
            $rebuilt .= $source->slice($line->spanWithTerminator());
        }

        self::assertSame($bytes, $rebuilt);
    }

    public function testLoneCarriageReturnInput(): void
    {
        $source = Source::fromString("\r");

        self::assertSame(1, $source->lineCount());
        self::assertSame(0, $source->line(0)->span->length);
        self::assertSame("\r", $source->line(0)->terminator);
    }

    public function testCrlfOnlyInput(): void
    {
        $source = Source::fromString("\r\n\r\n");

        self::assertSame(2, $source->lineCount());
        self::assertSame("\r\n", $source->line(0)->terminator);
        self::assertSame("\r\n", $source->line(1)->terminator);
        self::assertTrue($source->line(0)->isBlank());
        self::assertTrue($source->line(1)->isBlank());
    }

    public function testLineIndices(): void
    {
        $source = Source::fromString("a\nb\nc");

        self::assertSame(0, $source->line(0)->index);
        self::assertSame(1, $source->line(1)->index);
        self::assertSame(2, $source->line(2)->index);
    }

    public function testIterationYieldsLines(): void
    {
        $source = Source::fromString("a\nb");

        $lines = iterator_to_array($source);

        self::assertCount(2, $lines);
        self::assertContainsOnlyInstancesOf(Line::class, $lines);
        self::assertSame($source->lines(), $lines);
    }

    public function testSliceArbitrarySpanCrossesLines(): void
    {
        $source = Source::fromString("ab\ncd\n");

        self::assertSame("b\nc", $source->slice(ByteSpan::between(1, 4)));
    }

    public function testMultibyteContentIsMeasuredInBytes(): void
    {
        $source = Source::fromString("h\u{E9}llo\nw\u{F6}rld");

        self::assertSame(2, $source->lineCount());
        self::assertSame(6, $source->line(0)->span->length);
        self::assertSame(7, $source->line(1)->span->start);
        self::assertSame(6, $source->line(1)->span->length);
        self::assertSame("h\u{E9}llo", $source->slice($source->line(0)->span));
        self::assertSame("w\u{F6}rld", $source->slice($source->line(1)->span));
    }

    public function testUtf8BomIsSkippedButPreserved(): void
    {
        $source = Source::fromString("\u{FEFF}title\n");

        self::assertTrue($source->hasBom());
        self::assertSame(1, $source->lineCount());
        self::assertSame(3, $source->line(0)->span->start);
        self::assertSame('title', $source->slice($source->line(0)->span));
        self::assertSame("\u{FEFF}title\n", $source->bytes);
    }

    public function testBomOnlyInput(): void
    {
        $source = Source::fromString("\u{FEFF}");

        self::assertTrue($source->hasBom());
        self::assertSame(0, $source->lineCount());
        self::assertSame("\u{FEFF}", $source->bytes);
    }

    public function testBomBytesAfterStartAreContent(): void
    {
        $source = Source::fromString("a\n\u{FEFF}b");

        self::assertFalse($source->hasBom());
        self::assertSame(2, $source->lineCount());
        self::assertSame("\u{FEFF}b", $source->slice($source->line(1)->span));
    }

    public function testNegativeLineIndexIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Source::fromString('a')->line(-1);
    }

    public function testLineIndexPastEndIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Source::fromString('a')->line(1);
    }

    public function testSlicePastEndIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Source::fromString('ab')->slice(ByteSpan::between(1, 3));
    }
}
