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
use Alto\Rst\Node\Inline\Strong;
use Alto\Rst\Parser\InlineParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(InlineParser::class)]
final class InlineParserEmphasisTest extends InlineParserTestCase
{
    #[DataProvider('emphasisCases')]
    public function testEmphasisAndStrong(string $text, string $expected): void
    {
        self::assertSame($expected, self::outline($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function emphasisCases(): iterable
    {
        yield 'emphasis' => ['Text with *emphasis* inside.', 'text(Text with )em(text(emphasis))text( inside.)'];
        yield 'strong' => ['Text with **strong** inside.', 'text(Text with )strong(text(strong))text( inside.)'];
        yield 'adjacent to punctuation' => ['*a*, **b**.', 'em(text(a))text(, )strong(text(b))text(.)'];
        yield 'at both ends' => ['*a* and *b*', 'em(text(a))text( and )em(text(b))'];
        yield 'asterisk inside strong' => ['**a*b**', 'strong(text(a*b))'];
        yield 'first end-string wins' => ['*a *b* c*', 'em(text(a *b))text( c*)'];
        yield 'across lines' => ["*emphasis\n  across lines*", 'em(text(emphasis across lines))'];
        yield 'arithmetic is untouched' => ['5 * 3 * 2 = 30', 'text(5 * 3 * 2 = 30)'];
        yield 'inside a word' => ['not**markup**here', 'text(not**markup**here)'];
        yield 'start-string before whitespace' => ['a * b *', 'text(a * b *)'];
        yield 'empty strong' => ['a ** b', 'text(a ** b)'];
    }

    public function testChildSpansAddressTheContentBytes(): void
    {
        $emphasis = self::nodeAt(self::parseInline('Text *emphasis* here.'), 1, Emphasis::class);
        $child = self::nodeAt(array_values($emphasis->children()), 0, InlineText::class);

        self::assertSame([5, 15], self::bounds($emphasis));
        self::assertSame([6, 14], self::bounds($child));
    }

    public function testStrongWrapsItsChildren(): void
    {
        $strong = self::nodeAt(self::parseInline('**a**'), 0, Strong::class);

        self::assertSame([0, 5], self::bounds($strong));
        self::assertSame('a', self::nodeAt(array_values($strong->children()), 0, InlineText::class)->text);
    }

    public function testNestedMarkupIsParsedAndReported(): void
    {
        self::assertSame('text(Nested )em(text(a )literal(b)text( c))text( markup.)', self::outline('Nested *a ``b`` c* markup.'));
        self::assertSame(['inline/nested-markup'], self::problemCodes('Nested *a ``b`` c* markup.'));
    }

    public function testPlainEmphasisReportsNothing(): void
    {
        self::assertNoProblems('Text with *emphasis* and **strong** inside.');
    }

    public function testUnmatchedStartStringDegradesToText(): void
    {
        self::assertSame('text(Unclosed *emphasis here.)', self::outline('Unclosed *emphasis here.'));
        self::assertSame(['inline/unmatched-start-string'], self::problemCodes('Unclosed *emphasis here.'));
    }

    public function testUnmatchedStrongStartStringDegradesToText(): void
    {
        self::assertSame('text(Unclosed **strong here.)', self::outline('Unclosed **strong here.'));
        self::assertSame(['inline/unmatched-start-string'], self::problemCodes('Unclosed **strong here.'));
    }

    public function testEmptyContentIsNotMarkup(): void
    {
        self::assertSame('text(A **** row.)', self::outline('A **** row.'));
        self::assertSame(['inline/unmatched-start-string'], self::problemCodes('A **** row.'));
    }

    public function testEscapedEndStringDoesNotCloseEmphasis(): void
    {
        self::assertSame('text(Escaped )em(text(a*b))text( end.)', self::outline('Escaped *a\*b* end.'));
    }
}
