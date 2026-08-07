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

namespace Alto\Rst\Tests\Convert\Markdown;

use Alto\Rst\Convert\Markdown\BlockDraft;
use Alto\Rst\Convert\Markdown\DocumentDraft;
use Alto\Rst\Convert\Markdown\MarkdownBlockParser;
use Alto\Rst\Convert\Markdown\MarkdownInlineItem;
use Alto\Rst\Convert\Markdown\MarkdownInlineParser;
use Alto\Rst\Convert\Markdown\MarkdownReader;
use Alto\Rst\Convert\Markdown\MdEmphasis;
use Alto\Rst\Convert\Markdown\MdLineCursor;
use Alto\Rst\Convert\Markdown\MdNode;
use Alto\Rst\Convert\Markdown\MdParagraph;
use Alto\Rst\Convert\Markdown\MdStrong;
use Alto\Rst\Convert\Markdown\MdText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(MarkdownReader::class)]
#[CoversClass(BlockDraft::class)]
#[CoversClass(DocumentDraft::class)]
#[CoversClass(MarkdownBlockParser::class)]
#[CoversClass(MarkdownInlineItem::class)]
#[CoversClass(MarkdownInlineParser::class)]
#[CoversClass(MdLineCursor::class)]
#[CoversClass(MdEmphasis::class)]
#[CoversClass(MdStrong::class)]
#[CoversClass(MdText::class)]
final class MarkdownReaderEmphasisTest extends MarkdownReaderTestCase
{
    /**
     * @return list<MdNode>
     */
    private static function inline(string $markdown): array
    {
        $paragraph = self::read($markdown)->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);

        return $paragraph->children();
    }

    #[DataProvider('emphasisMarkers')]
    public function testEmphasis(string $markdown, string $marker): void
    {
        $inline = self::inline($markdown);
        self::assertCount(1, $inline);
        $emphasis = $inline[0];
        self::assertInstanceOf(MdEmphasis::class, $emphasis);
        self::assertSame($marker, $emphasis->marker);
        $text = $emphasis->children()[0];
        self::assertInstanceOf(MdText::class, $text);
        self::assertSame('em', $text->text);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function emphasisMarkers(): iterable
    {
        yield 'asterisk' => ["*em*\n", '*'];
        yield 'underscore' => ["_em_\n", '_'];
    }

    #[DataProvider('strongMarkers')]
    public function testStrong(string $markdown, string $marker): void
    {
        $inline = self::inline($markdown);
        self::assertCount(1, $inline);
        $strong = $inline[0];
        self::assertInstanceOf(MdStrong::class, $strong);
        self::assertSame($marker, $strong->marker);
        $text = $strong->children()[0];
        self::assertInstanceOf(MdText::class, $text);
        self::assertSame('strong', $text->text);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function strongMarkers(): iterable
    {
        yield 'double asterisk' => ["**strong**\n", '**'];
        yield 'double underscore' => ["__strong__\n", '__'];
    }

    public function testEmphasisAndTextAroundIt(): void
    {
        $inline = self::inline("foo *bar* baz\n");
        self::assertCount(3, $inline);
        self::assertInstanceOf(MdText::class, $inline[0]);
        self::assertSame('foo ', $inline[0]->text);
        self::assertInstanceOf(MdEmphasis::class, $inline[1]);
        self::assertInstanceOf(MdText::class, $inline[2]);
        self::assertSame(' baz', $inline[2]->text);
    }

    public function testTripleAsteriskNestsStrongInsideEmphasis(): void
    {
        $inline = self::inline("***both***\n");
        self::assertCount(1, $inline);
        $emphasis = $inline[0];
        self::assertInstanceOf(MdEmphasis::class, $emphasis);
        self::assertSame('*', $emphasis->marker);
        $strong = $emphasis->children()[0];
        self::assertInstanceOf(MdStrong::class, $strong);
        self::assertSame('**', $strong->marker);
        $text = $strong->children()[0];
        self::assertInstanceOf(MdText::class, $text);
        self::assertSame('both', $text->text);
    }

    public function testIntrawordUnderscoreIsNotEmphasis(): void
    {
        $inline = self::inline("foo_bar_baz\n");
        self::assertCount(1, $inline);
        self::assertInstanceOf(MdText::class, $inline[0]);
        self::assertSame('foo_bar_baz', $inline[0]->text);
    }

    public function testIntrawordAsteriskIsEmphasis(): void
    {
        $inline = self::inline("foo*bar*baz\n");
        self::assertCount(3, $inline);
        self::assertInstanceOf(MdEmphasis::class, $inline[1]);
    }

    public function testUnmatchedAsteriskIsLiteralText(): void
    {
        $inline = self::inline("a * b\n");
        self::assertCount(1, $inline);
        self::assertInstanceOf(MdText::class, $inline[0]);
        self::assertSame('a * b', $inline[0]->text);
    }

    public function testBackslashEscapedAsteriskIsNotEmphasis(): void
    {
        $inline = self::inline("\\*not emphasis\\*\n");
        self::assertCount(1, $inline);
        self::assertInstanceOf(MdText::class, $inline[0]);
        self::assertSame('*not emphasis*', $inline[0]->text);
    }

    public function testBackslashEscapedBackslash(): void
    {
        $inline = self::inline("a\\\\b\n");
        self::assertCount(1, $inline);
        self::assertInstanceOf(MdText::class, $inline[0]);
        self::assertSame('a\\b', $inline[0]->text);
    }

    public function testBackslashBeforeNonPunctuationIsLiteral(): void
    {
        $inline = self::inline("a\\qb\n");
        self::assertCount(1, $inline);
        self::assertInstanceOf(MdText::class, $inline[0]);
        self::assertSame('a\\qb', $inline[0]->text);
    }

    public function testEmphasisSpanCoversTheDelimiters(): void
    {
        $paragraph = self::read("*em*\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $emphasis = $paragraph->children()[0];
        self::assertInstanceOf(MdEmphasis::class, $emphasis);
        self::assertSpan(0, 4, $emphasis);
    }
}
