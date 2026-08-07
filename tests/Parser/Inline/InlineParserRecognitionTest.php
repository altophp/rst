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

use Alto\Rst\Parser\InlineParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The docutils start-string, end-string, and quoting rules.
 */
#[CoversClass(InlineParser::class)]
final class InlineParserRecognitionTest extends InlineParserTestCase
{
    #[DataProvider('startContextCases')]
    public function testStartStringContext(string $text, bool $recognized): void
    {
        self::assertSame($recognized, str_contains(self::outline($text), 'em('));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function startContextCases(): iterable
    {
        yield 'start of text' => ['*a* rest', true];
        yield 'after whitespace' => ['x *a* rest', true];
        yield 'after a line break' => ["x\n*a* rest", true];
        yield 'after a hyphen' => ['x-*a* rest', true];
        yield 'after a colon' => ['x:*a* rest', true];
        yield 'after a slash' => ['x/*a* rest', true];
        yield 'after an opening parenthesis' => ['x (*a*) rest', true];
        yield 'after an opening bracket' => ['x [*a*] rest', true];
        yield 'after an opening brace' => ['x {*a*} rest', true];
        yield 'after a double quote' => ['x "*a*" rest', true];
        yield 'after a single quote' => ["x '*a*' rest", true];
        yield 'after a less-than sign' => ['x <*a*> rest', true];
        yield 'after a letter' => ['xx*a* rest', false];
        yield 'after a digit' => ['1*a* rest', false];
        yield 'after a closing parenthesis' => ['x)*a* rest', false];
    }

    #[DataProvider('endContextCases')]
    public function testEndStringContext(string $text, bool $recognized): void
    {
        self::assertSame($recognized, str_contains(self::outline($text), 'em('));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function endContextCases(): iterable
    {
        yield 'end of text' => ['x *a*', true];
        yield 'before whitespace' => ['x *a* y', true];
        yield 'before a full stop' => ['x *a*.', true];
        yield 'before a comma' => ['x *a*, y', true];
        yield 'before a semicolon' => ['x *a*; y', true];
        yield 'before a colon' => ['x *a*: y', true];
        yield 'before an exclamation mark' => ['x *a*! y', true];
        yield 'before a question mark' => ['x *a*? y', true];
        yield 'before a hyphen' => ['x *a*-y', true];
        yield 'before a slash' => ['x *a*/y', true];
        yield 'before a closing parenthesis' => ['x (*a*) y', true];
        yield 'before a closing bracket' => ['x [*a*] y', true];
        yield 'before a closing brace' => ['x {*a*} y', true];
        yield 'before a greater-than sign' => ['x <*a*> y', true];
        yield 'before a letter' => ['x *a*y', false];
        yield 'before an underscore' => ['x *a*_ y', false];
        yield 'after whitespace' => ['x *a * y', false];
    }

    #[DataProvider('quotingCases')]
    public function testQuotedStartStrings(string $text, string $expected): void
    {
        self::assertSame($expected, self::outline($text));
        self::assertNoProblems($text);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function quotingCases(): iterable
    {
        yield 'double quotes' => ['A "*" is text.', 'text(A "*" is text.)'];
        yield 'single quotes' => ["A '*' is text.", "text(A '*' is text.)"];
        yield 'parentheses' => ['A (*) is text.', 'text(A (*) is text.)'];
        yield 'braces' => ['A {*} is text.', 'text(A {*} is text.)'];
        yield 'angle brackets' => ['A <*> is text.', 'text(A <*> is text.)'];
        yield 'quoted at the end of the text' => ['A (*', 'text(A (*)'];
        yield 'quoted backquote' => ['A (`) is text.', 'text(A (`) is text.)'];
        yield 'quoted pipe' => ['A (|) is text.', 'text(A (|) is text.)'];
        yield 'quoted markup is still markup' => ['A "*b*" is markup.', 'text(A ")em(text(b))text(" is markup.)'];
        yield 'parenthesized markup is still markup' => ['A (**b**) is markup.', 'text(A ()strong(text(b))text() is markup.)'];
    }

    #[DataProvider('startStringOrderCases')]
    public function testTheLongestStartStringWins(string $text, string $expected): void
    {
        self::assertSame($expected, self::outline($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function startStringOrderCases(): iterable
    {
        yield 'strong before emphasis' => ['**a**', 'strong(text(a))'];
        yield 'literal before interpreted text' => ['``a``', 'literal(a)'];
        yield 'emphasis inside strong bounds' => ['**a** *b*', 'strong(text(a))text( )em(text(b))'];
        yield 'interpreted text after a literal' => ['``a`` `b`', 'literal(a)text( )interpreted(-,b,suffix)'];
    }

    public function testMarkupIsRecognizedAfterAMultiByteCharacterOnlyWithAsciiContext(): void
    {
        self::assertSame("text(\u{ab}*a*\u{bb} rest)", self::outline("\u{ab}*a*\u{bb} rest"));
    }

    public function testMultiByteNamesStayWhole(): void
    {
        self::assertSame("text(A )reference(caf\u{e9},simple)text( link.)", self::outline("A caf\u{e9}_ link."));
    }
}
