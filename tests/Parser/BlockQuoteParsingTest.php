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

use Alto\Rst\Node\BlockQuote;
use Alto\Rst\Node\Paragraph;
use PHPUnit\Framework\Attributes\CoversNamespace;

#[CoversNamespace('Alto\\Rst\\Parser')]
final class BlockQuoteParsingTest extends ParserTestCase
{
    public function testIndentedBlockBecomesBlockQuote(): void
    {
        $result = self::parseRst("Lead paragraph.\n\n    Quoted text.\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        $quote = $children[1];
        self::assertInstanceOf(BlockQuote::class, $quote);
        self::assertSpan(17, 33, $quote);

        $inner = $quote->children();
        self::assertCount(1, $inner);
        self::assertInstanceOf(Paragraph::class, $inner[0]);
        self::assertSame('Quoted text.', $inner[0]->text->text);
        self::assertNoProblems($result);
    }

    public function testBlankSeparatedIndentedBlocksMergeIntoOneQuote(): void
    {
        $result = self::parseRst("Lead.\n\n    First quoted.\n\n    Second quoted.\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        $quote = $children[1];
        self::assertInstanceOf(BlockQuote::class, $quote);
        self::assertCount(2, $quote->children());
        self::assertNoProblems($result);
    }

    public function testDeeperIndentationNestsQuotes(): void
    {
        $result = self::parseRst("Lead.\n\n    Outer quote.\n\n        Inner quote.\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        $outer = $children[1];
        self::assertInstanceOf(BlockQuote::class, $outer);

        $inner = $outer->children();
        self::assertCount(2, $inner);
        self::assertInstanceOf(Paragraph::class, $inner[0]);
        self::assertInstanceOf(BlockQuote::class, $inner[1]);
        self::assertNoProblems($result);
    }

    public function testDocumentMayStartWithABlockQuote(): void
    {
        $result = self::parseRst("    Quoted from the start.\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(BlockQuote::class, $children[0]);
        self::assertNoProblems($result);
    }

    public function testAttributionDegradesToParagraph(): void
    {
        $result = self::parseRst("Lead.\n\n    Quoted text.\n\n    -- Attribution Name\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        $quote = $children[1];
        self::assertInstanceOf(BlockQuote::class, $quote);

        $inner = $quote->children();
        self::assertCount(2, $inner);
        self::assertInstanceOf(Paragraph::class, $inner[1]);
        self::assertSame('-- Attribution Name', $inner[1]->text->text);
        self::assertNoProblems($result);
    }
}
