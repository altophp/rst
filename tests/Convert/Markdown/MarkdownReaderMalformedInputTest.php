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
use Alto\Rst\Convert\Markdown\MdDocument;
use Alto\Rst\Convert\Markdown\MdLineCursor;
use Alto\Rst\Convert\Markdown\MdParagraph;
use Alto\Rst\Convert\Markdown\MdText;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MarkdownReader::read() never throws: anything unrecognised degrades to
 * text or to a paragraph. These cases exercise the "it must not throw"
 * contract rather than any single construct's happy path.
 */
#[CoversClass(MarkdownReader::class)]
#[CoversClass(BlockDraft::class)]
#[CoversClass(DocumentDraft::class)]
#[CoversClass(MarkdownBlockParser::class)]
#[CoversClass(MarkdownInlineItem::class)]
#[CoversClass(MarkdownInlineParser::class)]
#[CoversClass(MdLineCursor::class)]
final class MarkdownReaderMalformedInputTest extends MarkdownReaderTestCase
{
    #[DataProvider('pathologicalInputs')]
    public function testNeverThrows(string $markdown): void
    {
        $document = self::read($markdown);

        self::assertInstanceOf(MdDocument::class, $document);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathologicalInputs(): iterable
    {
        yield 'empty string' => [''];
        yield 'only whitespace' => ["   \n\t\n   "];
        yield 'unmatched open brackets' => ["[[[[[[\n"];
        yield 'unmatched close brackets' => ["]]]]]]\n"];
        yield 'unterminated link destination' => ["[text](\n"];
        yield 'unterminated fenced code' => ["```\nno closing fence"];
        yield 'unterminated code span' => ['`unterminated'];
        yield 'unterminated inline html' => ['<div class="'];
        yield 'unterminated blockquote' => ['> '];
        yield 'unterminated list marker' => ['-'];
        yield 'unterminated ordered marker' => ['1.'];
        yield 'unterminated table header only' => ["| a | b\n"];
        yield 'unterminated table delimiter only' => ["| a | b |\n| - | -\n"];
        yield 'trailing backslash' => ['trailing\\'];
        yield 'long delimiter run' => [str_repeat('*', 500) . "\n"];
        yield 'long backtick run' => [str_repeat('`', 500) . "\n"];
        yield 'deeply nested blockquotes' => [str_repeat('> ', 200) . "deep\n"];
        yield 'deeply nested emphasis' => [str_repeat('*', 40) . 'x' . str_repeat('*', 40) . "\n"];
        yield 'null byte in content' => ["a\0b\n"];
        yield 'invalid utf-8 byte' => ["a\xffb\n"];
        yield 'crlf line endings' => ["# Heading\r\n\r\nParagraph.\r\n"];
        yield 'lone cr line endings' => ["# Heading\rParagraph.\r"];
        yield 'utf-8 bom' => ["\xEF\xBB\xBFHello.\n"];
        yield 'malformed link reference definition' => ["[label]:\n"];
        yield 'reference link with empty label and no default' => ["[][]\n"];
        yield 'image without destination' => ['![alt]('];
        yield 'html comment without closing' => ['<!-- unterminated'];
        yield 'mismatched table columns' => ["| a | b | c |\n| - | - |\n| 1 |\n"];
        yield 'setext underline alone' => ["===\n"];
        yield 'nested lists with mixed markers' => ["- a\n  * b\n    1. c\n"];
        yield 'backslash before end of document' => ['\\'];
        yield 'lone angle bracket' => ["<\n"];
        yield 'lone exclamation before bracket' => ["![\n"];
    }

    public function testUnmatchedOpenBracketDegradesToLiteralText(): void
    {
        $paragraph = self::read("[not a link\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $text = $paragraph->children()[0];
        self::assertInstanceOf(MdText::class, $text);
        self::assertSame('[not a link', $text->text);
    }

    public function testSetextUnderlineWithNoPrecedingParagraphIsNotAHeading(): void
    {
        $document = self::read("===\n");
        self::assertCount(1, $document->children());
        self::assertInstanceOf(MdParagraph::class, $document->children()[0]);
    }

    public function testUnterminatedLinkDestinationFallsBackToLiteralBracket(): void
    {
        $paragraph = self::read("[text](\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $inline = $paragraph->children();
        self::assertInstanceOf(MdText::class, $inline[0]);
        self::assertStringContainsString('[text]', $inline[0]->text);
    }

    public function testResultIsStableAcrossRepeatedReads(): void
    {
        $markdown = "# Title\n\nSome *text* with a [link](https://example.com).\n";
        $first = self::read($markdown);
        $second = self::read($markdown);

        self::assertEquals($first, $second);
    }

    public function testBlockDraftExposesItsSourceSpan(): void
    {
        $span = ByteSpan::of(7, 4);

        self::assertSame($span, BlockDraft::paragraph($span, 'text', 7)->span());
    }
}
