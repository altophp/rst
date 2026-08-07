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

namespace Alto\Rst\Tests\Parser;

use Alto\Rst\Parser\LineCursor;
use Alto\Rst\Parser\ParserLine;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\TestCase;

#[CoversNamespace('Alto\\Rst\\Parser')]
final class LineCursorTest extends TestCase
{
    public function testAnEmptyCursorIsAtEnd(): void
    {
        $cursor = new LineCursor([]);

        self::assertTrue($cursor->atEnd());
        self::assertNull($cursor->peek());
    }

    public function testAdvancingWalksToTheEnd(): void
    {
        $cursor = new LineCursor(self::lines("one\ntwo\n"));

        self::assertFalse($cursor->atEnd());
        $cursor->advance();
        $cursor->advance();
        self::assertTrue($cursor->atEnd());
    }

    public function testPeekLooksAheadWithoutMoving(): void
    {
        $lines = self::lines("one\ntwo\n");
        $cursor = new LineCursor($lines);

        self::assertSame($lines[0], $cursor->peek());
        self::assertSame($lines[1], $cursor->peek(1));
        self::assertNull($cursor->peek(2));
        self::assertSame(0, $cursor->position());
    }

    public function testPreviousReturnsTheLineBehindTheCursor(): void
    {
        $lines = self::lines("one\ntwo\n");
        $cursor = new LineCursor($lines);

        self::assertNull($cursor->previous());
        $cursor->advance();
        self::assertSame($lines[0], $cursor->previous());
    }

    public function testSeekMovesToAnAbsolutePosition(): void
    {
        $lines = self::lines("one\ntwo\nthree\n");
        $cursor = new LineCursor($lines);

        $cursor->seek(2);
        self::assertSame(2, $cursor->position());
        self::assertSame($lines[2], $cursor->peek());
        $cursor->seek(0);
        self::assertSame($lines[0], $cursor->peek());
    }

    public function testSkipBlankLinesStopsAtTheFirstContentLine(): void
    {
        $lines = self::lines("\n\ncontent\n");
        $cursor = new LineCursor($lines);

        $cursor->skipBlankLines();

        self::assertSame(2, $cursor->position());
        $line = $cursor->peek();
        self::assertNotNull($line);
        self::assertFalse($line->blank);
    }

    public function testSkipBlankLinesAtTheEndReachesTheEnd(): void
    {
        $cursor = new LineCursor(self::lines("\n\n"));

        $cursor->skipBlankLines();

        self::assertTrue($cursor->atEnd());
    }

    /**
     * @return list<ParserLine>
     */
    private static function lines(string $text): array
    {
        $lines = [];

        foreach (Source::fromString($text)->lines() as $line) {
            $lines[] = ParserLine::fromLine($line);
        }

        return $lines;
    }
}
