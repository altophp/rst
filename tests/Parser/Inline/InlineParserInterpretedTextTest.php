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

use Alto\Rst\Node\Inline\InterpretedText;
use Alto\Rst\Parser\InlineParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(InlineParser::class)]
final class InlineParserInterpretedTextTest extends InlineParserTestCase
{
    #[DataProvider('interpretedCases')]
    public function testInterpretedText(string $text, string $expected): void
    {
        self::assertSame($expected, self::outline($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function interpretedCases(): iterable
    {
        yield 'role prefix' => [':title-reference:`Book Title`', 'interpreted(title-reference,Book Title,prefix)'];
        yield 'role suffix' => ['`text`:title-reference:', 'interpreted(title-reference,text,suffix)'];
        yield 'default role' => ['a `default role`.', 'text(a )interpreted(-,default role,suffix)text(.)'];
        yield 'namespaced role' => [':py:class:`Foo`', 'interpreted(py:class,Foo,prefix)'];
        yield 'role on a literal is not a role' => [':role:``literal``', 'text(:role:)literal(literal)'];
        yield 'colon alone' => ['Colon : alone and :: too.', 'text(Colon : alone and :: too.)'];
        yield 'across lines' => ["`wrapped\n  role`", 'interpreted(-,wrapped role,suffix)'];
        yield 'markup inside is verbatim' => ['`a *b* c`', 'interpreted(-,a *b* c,suffix)'];
    }

    public function testRolePrefixSpanCoversTheRole(): void
    {
        $node = self::nodeAt(self::parseInline('A :ref:`target` here.'), 1, InterpretedText::class);

        self::assertSame([2, 15], self::bounds($node));
        self::assertSame('ref', $node->role);
        self::assertTrue($node->rolePrefix);
    }

    public function testRoleSuffixSpanCoversTheRole(): void
    {
        $node = self::nodeAt(self::parseInline('A `target`:ref: here.'), 1, InterpretedText::class);

        self::assertSame([2, 15], self::bounds($node));
        self::assertSame('ref', $node->role);
        self::assertFalse($node->rolePrefix);
    }

    public function testEscapesAreDecodedInsideInterpretedText(): void
    {
        $node = self::nodeAt(self::parseInline('`a \\* b`'), 0, InterpretedText::class);

        self::assertSame('a * b', $node->text);
    }

    public function testRolePrefixWithAReferenceSuffixIsMalformed(): void
    {
        self::assertSame(['inline/malformed-role'], self::problemCodes('Bad :role:`text`_ suffix.'));
    }

    public function testRolePrefixWithARoleSuffixIsMalformed(): void
    {
        self::assertSame(['inline/malformed-role'], self::problemCodes('Bad :one:`text`:two: suffix.'));
    }

    public function testUnmatchedStartStringIsReportedOnce(): void
    {
        self::assertSame(['inline/unmatched-start-string'], self::problemCodes('A :role:`unclosed here.'));
        self::assertSame(['inline/unmatched-start-string'], self::problemCodes('A `unclosed here.'));
    }

    public function testBackquoteBeforeWhitespaceIsNotMarkup(): void
    {
        self::assertSame('text(A ` tick.)', self::outline('A ` tick.'));
        self::assertNoProblems('A ` tick.');
    }

    public function testInterpretedTextReportsNothing(): void
    {
        self::assertNoProblems('A :title-reference:`Book Title` and a `default role`.');
    }
}
