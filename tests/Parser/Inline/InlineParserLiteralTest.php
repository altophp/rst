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

use Alto\Rst\Node\Inline\InlineLiteral;
use Alto\Rst\Parser\InlineParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(InlineParser::class)]
final class InlineParserLiteralTest extends InlineParserTestCase
{
    #[DataProvider('literalCases')]
    public function testInlineLiteral(string $text, string $expected): void
    {
        self::assertSame($expected, self::outline($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function literalCases(): iterable
    {
        yield 'literal' => ['Text with ``literal`` inside.', 'text(Text with )literal(literal)text( inside.)'];
        yield 'markup is verbatim' => ['``*not emphasis*``', 'literal(*not emphasis*)'];
        yield 'backslashes are verbatim' => ['``a\\b``', 'literal(a\\b)'];
        yield 'single backquote inside' => ['``a ` b``', 'literal(a ` b)'];
        yield 'repeated spaces survive' => ['``a   b``', 'literal(a   b)'];
        yield 'start-string before whitespace' => ['Empty `` ticks.', 'text(Empty `` ticks.)'];
        yield 'empty content' => ['A ```` row.', 'text(A ```` row.)'];
    }

    public function testLineBreaksInsideALiteralBecomeOneSpace(): void
    {
        $literal = self::nodeAt(self::parseInline("``literal\n    block``"), 0, InlineLiteral::class);

        self::assertSame('literal block', $literal->text);
        self::assertSame([0, 21], self::bounds($literal));
    }

    public function testUnclosedLiteralDegradesToText(): void
    {
        self::assertSame('text(Unclosed ``literal here.)', self::outline('Unclosed ``literal here.'));
        self::assertSame(['inline/unclosed-literal'], self::problemCodes('Unclosed ``literal here.'));
    }

    public function testAReferenceSuffixDoesNotCloseALiteral(): void
    {
        self::assertSame(['inline/unclosed-literal'], self::problemCodes('A ``literal``_ oddity.'));
    }

    public function testLiteralReportsNothing(): void
    {
        self::assertNoProblems('Text with ``literal`` inside.');
    }
}
