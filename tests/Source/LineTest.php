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
use Alto\Rst\Source\Line;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Line::class)]
final class LineTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function provideIndentation(): iterable
    {
        yield 'no indent' => ['text', 0, 0];
        yield 'spaces' => ['   text', 3, 3];
        yield 'single tab' => ["\ttext", 1, 8];
        yield 'tab after one space' => [" \ttext", 2, 8];
        yield 'tab after seven spaces' => ["       \ttext", 8, 8];
        yield 'tab after eight spaces' => ["        \ttext", 9, 16];
        yield 'tab then spaces' => ["\t  text", 3, 10];
        yield 'two tabs' => ["\t\ttext", 2, 16];
    }

    #[DataProvider('provideIndentation')]
    public function testIndentation(string $input, int $expectedBytes, int $expectedWidth): void
    {
        $line = Source::fromString($input)->line(0);

        self::assertSame($expectedBytes, $line->indentBytes);
        self::assertSame($expectedWidth, $line->indentWidth);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideBlankness(): iterable
    {
        yield 'empty' => ['', true];
        yield 'spaces only' => ['   ', true];
        yield 'tabs only' => ["\t\t", true];
        yield 'mixed whitespace' => [" \t \t ", true];
        yield 'vertical tab and form feed' => ["\x0B\f", true];
        yield 'text' => ['x', false];
        yield 'indented text' => ['    x', false];
    }

    #[DataProvider('provideBlankness')]
    public function testBlankDetection(string $content, bool $expected): void
    {
        $line = Source::fromString($content . "\nend")->line(0);

        self::assertSame($expected, $line->isBlank());
    }

    public function testContentSpanExcludesIndentation(): void
    {
        $source = Source::fromString("    body\n");
        $line = $source->line(0);

        self::assertSame(4, $line->contentSpan()->start);
        self::assertSame(4, $line->contentSpan()->length);
        self::assertSame('body', $source->slice($line->contentSpan()));
    }

    public function testContentSpanOfWhitespaceOnlyLineIsEmpty(): void
    {
        $line = Source::fromString("   \nend")->line(0);

        self::assertSame(3, $line->indentBytes);
        self::assertTrue($line->contentSpan()->isEmpty());
        self::assertSame(3, $line->contentSpan()->start);
    }

    public function testSpanWithTerminator(): void
    {
        $line = Source::fromString("ab\r\ncd")->line(0);

        self::assertSame(0, $line->spanWithTerminator()->start);
        self::assertSame(4, $line->spanWithTerminator()->length);
    }

    public function testSpanWithoutTerminatorMatchesSpan(): void
    {
        $line = Source::fromString('ab')->line(0);

        self::assertSame($line->span->start, $line->spanWithTerminator()->start);
        self::assertSame($line->span->length, $line->spanWithTerminator()->length);
    }

    public function testMultibyteIndentedContent(): void
    {
        $source = Source::fromString("  \u{E9}t\u{E9}");
        $line = $source->line(0);

        self::assertSame(2, $line->indentBytes);
        self::assertSame(2, $line->indentWidth);
        self::assertSame("\u{E9}t\u{E9}", $source->slice($line->contentSpan()));
    }

    public function testScanRejectsUnknownTerminator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Line::scan('ab', 0, 0, 1, 'x');
    }

    public function testScanRejectsEndPastByteLength(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Line::scan('ab', 0, 0, 3, '');
    }
}
