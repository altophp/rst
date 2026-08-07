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

namespace Alto\Rst\Tests\Parser\Inline;

use Alto\Rst\Node\Inline\Emphasis;
use Alto\Rst\Node\Inline\InlineText;
use Alto\Rst\Parser\InlineParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(InlineParser::class)]
final class InlineParserTextTest extends InlineParserTestCase
{
    public function testEmptyInputProducesNoNodes(): void
    {
        self::assertSame([], self::parseInline(''));
    }

    public function testPlainTextBecomesASingleNode(): void
    {
        $nodes = self::parseInline('Plain text.');
        $text = self::nodeAt($nodes, 0, InlineText::class);

        self::assertCount(1, $nodes);
        self::assertSame('Plain text.', $text->text);
        self::assertSame([0, 11], self::bounds($text));
    }

    public function testSpansAreRelativeToTheBaseOffset(): void
    {
        $nodes = self::parseInline('a *b* c', 100);

        self::assertSame([100, 102], self::bounds(self::nodeAt($nodes, 0, InlineText::class)));
        self::assertSame([102, 105], self::bounds(self::nodeAt($nodes, 1, Emphasis::class)));
        self::assertSame([105, 107], self::bounds(self::nodeAt($nodes, 2, InlineText::class)));
    }

    /**
     * @param non-empty-string $text
     */
    #[DataProvider('whitespaceCases')]
    public function testWhitespaceRunsCollapseToOneSpace(string $text, string $expected): void
    {
        $node = self::nodeAt(self::parseInline($text), 0, InlineText::class);

        self::assertSame($expected, $node->text);
        self::assertSame([0, \strlen($text)], self::bounds($node));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function whitespaceCases(): iterable
    {
        yield 'single space' => ['a b', 'a b'];
        yield 'repeated spaces' => ['a   b', 'a b'];
        yield 'tab' => ["a\tb", 'a b'];
        yield 'line break with indentation' => ["a\n   b", 'a b'];
        yield 'trailing space before a line break' => ["a  \n  b", 'a b'];
        yield 'whitespace only' => ['   ', ' '];
    }

    #[DataProvider('escapeCases')]
    public function testBackslashEscapes(string $text, string $expected): void
    {
        self::assertSame($expected, self::nodeAt(self::parseInline($text), 0, InlineText::class)->text);
        self::assertNoProblems($text);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escapeCases(): iterable
    {
        yield 'escaped emphasis' => ['Not \*emphasis\* here.', 'Not *emphasis* here.'];
        yield 'escaped literal' => ['Not \``literal``.', 'Not ``literal``.'];
        yield 'escaped backslash' => ['A \\\\ backslash.', 'A \\ backslash.'];
        yield 'escaped space vanishes' => ['A space \ vanishes.', 'A space vanishes.'];
        yield 'escaped line break joins' => ["Joined \\\n  words.", 'Joined words.'];
        yield 'trailing backslash vanishes' => ['Trailing \\', 'Trailing '];
        yield 'escaped plain character' => ['A \x letter.', 'A x letter.'];
    }

    public function testAnEscapedRunKeepsItsRawSpan(): void
    {
        $node = self::nodeAt(self::parseInline('a\\ b'), 0, InlineText::class);

        self::assertSame('ab', $node->text);
        self::assertSame([0, 4], self::bounds($node));
    }

    public function testEscapedWhitespaceJoinsMarkupToTheNextWord(): void
    {
        self::assertSame('strong(text(bold))text(s plural.)', self::outline('**bold**\ s plural.'));
    }
}
