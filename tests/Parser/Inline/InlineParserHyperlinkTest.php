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

use Alto\Rst\Node\Inline\HyperlinkReference;
use Alto\Rst\Node\Inline\StandaloneHyperlink;
use Alto\Rst\Parser\InlineParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(InlineParser::class)]
final class InlineParserHyperlinkTest extends InlineParserTestCase
{
    #[DataProvider('referenceCases')]
    public function testHyperlinkReferences(string $text, string $expected): void
    {
        self::assertSame($expected, self::outline($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function referenceCases(): iterable
    {
        yield 'simple name' => ['See name_ here.', 'text(See )reference(name,simple)text( here.)'];
        yield 'anonymous simple name' => ['See name__ here.', 'text(See )reference(name,anonymous,simple)text( here.)'];
        yield 'dotted name' => ['See foo.bar_ here.', 'text(See )reference(foo.bar,simple)text( here.)'];
        yield 'underscored name' => ['See snake_case_ here.', 'text(See )reference(snake_case,simple)text( here.)'];
        yield 'phrase' => ['See `example site`_ here.', 'text(See )reference(example site)text( here.)'];
        yield 'anonymous phrase' => ['See `example site`__ here.', 'text(See )reference(example site,anonymous)text( here.)'];
        yield 'embedded uri' => ['`page <https://example.com/>`_', 'reference(page,uri=https://example.com/)'];
        yield 'embedded uri only' => ['`<https://example.com/>`_', 'reference(https://example.com/,uri=https://example.com/)'];
        yield 'embedded alias' => ['`alias <target_>`_', 'reference(alias,uri=target_)'];
        yield 'name inside a word' => ['use snake_case.', 'text(use snake_case.)'];
        yield 'name without a suffix' => ['a name here', 'text(a name here)'];
    }

    public function testEmbeddedUriDropsInternalWhitespace(): void
    {
        $node = self::nodeAt(self::parseInline("`page <https://example.com/\n  long>`_"), 0, HyperlinkReference::class);

        self::assertSame('page', $node->text);
        self::assertSame('https://example.com/long', $node->embeddedUri);
    }

    public function testSimpleReferenceSpan(): void
    {
        $node = self::nodeAt(self::parseInline('See name_ here.'), 1, HyperlinkReference::class);

        self::assertSame([4, 9], self::bounds($node));
        self::assertTrue($node->simple);
        self::assertNull($node->embeddedUri);
    }

    public function testPhraseReferenceSpan(): void
    {
        $node = self::nodeAt(self::parseInline('See `a b`__ here.'), 1, HyperlinkReference::class);

        self::assertSame([4, 11], self::bounds($node));
        self::assertFalse($node->simple);
        self::assertTrue($node->anonymous);
    }

    public function testAReferenceWithoutTextOrUriIsReported(): void
    {
        self::assertSame('text(Empty `< >`_ reference.)', self::outline('Empty `< >`_ reference.'));
        self::assertSame(['inline/empty-reference'], self::problemCodes('Empty `< >`_ reference.'));
    }

    #[DataProvider('standaloneCases')]
    public function testStandaloneHyperlinks(string $text, string $expected): void
    {
        self::assertSame($expected, self::outline($text));
        self::assertNoProblems($text);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function standaloneCases(): iterable
    {
        yield 'https' => ['Visit https://example.com/ today.', 'text(Visit )standalone(https://example.com/)text( today.)'];
        yield 'http' => ['Visit http://example.com today.', 'text(Visit )standalone(http://example.com)text( today.)'];
        yield 'ftp' => ['Visit ftp://example.com/pub today.', 'text(Visit )standalone(ftp://example.com/pub)text( today.)'];
        yield 'mailto' => ['Write mailto:user@example.com today.', 'text(Write )standalone(mailto:user@example.com)text( today.)'];
        yield 'uppercase scheme' => ['Visit HTTPS://EXAMPLE.COM/', 'text(Visit )standalone(HTTPS://EXAMPLE.COM/)'];
        yield 'trailing full stop' => ['Visit https://example.com/a.', 'text(Visit )standalone(https://example.com/a)text(.)'];
        yield 'trailing comma' => ['Visit https://example.com/a, then.', 'text(Visit )standalone(https://example.com/a)text(, then.)'];
        yield 'inner comma is kept' => ['Visit https://example.com/a,b/c.', 'text(Visit )standalone(https://example.com/a,b/c)text(.)'];
        yield 'balanced brackets are kept' => ['See https://example.com/Foo_(bar) here.', 'text(See )standalone(https://example.com/Foo_(bar))text( here.)'];
        yield 'unbalanced bracket is dropped' => ['See (https://example.com/a) here.', 'text(See ()standalone(https://example.com/a)text() here.)'];
        yield 'nested brackets' => ['See (https://example.com/a_(b)) here.', 'text(See ()standalone(https://example.com/a_(b))text() here.)'];
        yield 'angle brackets' => ['See <https://example.com/> here.', 'text(See <)standalone(https://example.com/)text(> here.)'];
        yield 'scheme without a body' => ['Write mailto: now.', 'text(Write mailto: now.)'];
        yield 'scheme inside a word' => ['xhttps://example.com/', 'text(xhttps://example.com/)'];
        yield 'not a scheme' => ['Visit example.com today.', 'text(Visit example.com today.)'];
    }

    public function testStandaloneHyperlinkSpan(): void
    {
        $node = self::nodeAt(self::parseInline('Visit https://example.com/ today.'), 1, StandaloneHyperlink::class);

        self::assertSame([6, 26], self::bounds($node));
        self::assertSame('https://example.com/', $node->uri);
    }

    public function testEmbeddedUriIsPreferredOverAStandaloneMatch(): void
    {
        $node = self::nodeAt(self::parseInline('`page <https://example.com/>`_'), 0, HyperlinkReference::class);

        self::assertSame('https://example.com/', $node->embeddedUri);
    }
}
