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
use Alto\Rst\Convert\Markdown\MdHeading;
use Alto\Rst\Convert\Markdown\MdHeadingStyle;
use Alto\Rst\Convert\Markdown\MdLineCursor;
use Alto\Rst\Convert\Markdown\MdParagraph;
use Alto\Rst\Convert\Markdown\MdText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(MarkdownReader::class)]
#[CoversClass(BlockDraft::class)]
#[CoversClass(DocumentDraft::class)]
#[CoversClass(MarkdownInlineItem::class)]
#[CoversClass(MarkdownInlineParser::class)]
#[CoversClass(MdLineCursor::class)]
#[CoversClass(MarkdownBlockParser::class)]
#[CoversClass(MdHeading::class)]
#[CoversClass(MdHeadingStyle::class)]
final class MarkdownReaderHeadingTest extends MarkdownReaderTestCase
{
    #[DataProvider('atxLevels')]
    public function testAtxHeadingLevel(string $markdown, int $expectedLevel): void
    {
        $children = self::read($markdown)->children();

        self::assertCount(1, $children);
        $heading = $children[0];
        self::assertInstanceOf(MdHeading::class, $heading);
        self::assertSame($expectedLevel, $heading->level);
        self::assertSame(MdHeadingStyle::Atx, $heading->style);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function atxLevels(): iterable
    {
        yield 'level 1' => ["# Heading\n", 1];
        yield 'level 2' => ["## Heading\n", 2];
        yield 'level 3' => ["### Heading\n", 3];
        yield 'level 4' => ["#### Heading\n", 4];
        yield 'level 5' => ["##### Heading\n", 5];
        yield 'level 6' => ["###### Heading\n", 6];
    }

    public function testAtxHeadingTextIsParsedAsInline(): void
    {
        $children = self::read("# Hello *world*\n")->children();
        $heading = $children[0];
        self::assertInstanceOf(MdHeading::class, $heading);
        $inline = $heading->children();
        self::assertCount(2, $inline);
        self::assertInstanceOf(MdText::class, $inline[0]);
        self::assertSame('Hello ', $inline[0]->text);
    }

    public function testAtxHeadingStripsClosingHashes(): void
    {
        $heading = self::read("## Heading ##\n")->children()[0];
        self::assertInstanceOf(MdHeading::class, $heading);
        $inline = $heading->children();
        self::assertInstanceOf(MdText::class, $inline[0]);
        self::assertSame('Heading', $inline[0]->text);
    }

    public function testAtxHeadingWithOnlyHashesIsEmpty(): void
    {
        $heading = self::read("# ###\n")->children()[0];
        self::assertInstanceOf(MdHeading::class, $heading);
        self::assertSame([], $heading->children());
    }

    public function testSevenHashesIsNotAHeading(): void
    {
        $node = self::read("####### Not a heading\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $node);
    }

    public function testHashWithoutSpaceIsNotAHeading(): void
    {
        $node = self::read("#no-space\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $node);
    }

    public function testAtxHeadingAllowsUpToThreeLeadingSpaces(): void
    {
        $node = self::read("   # Heading\n")->children()[0];
        self::assertInstanceOf(MdHeading::class, $node);
    }

    public function testFourLeadingSpacesIsIndentedCodeNotHeading(): void
    {
        $node = self::read("    # Heading\n")->children()[0];
        self::assertNotInstanceOf(MdHeading::class, $node);
    }

    public function testEmptyAtxHeadingHasNoText(): void
    {
        $heading = self::read("#\n")->children()[0];
        self::assertInstanceOf(MdHeading::class, $heading);
        self::assertSame(1, $heading->level);
        self::assertSame([], $heading->children());
    }

    #[DataProvider('setextLevels')]
    public function testSetextHeading(string $markdown, int $expectedLevel): void
    {
        $heading = self::read($markdown)->children()[0];
        self::assertInstanceOf(MdHeading::class, $heading);
        self::assertSame($expectedLevel, $heading->level);
        self::assertSame(MdHeadingStyle::Setext, $heading->style);
        $inline = $heading->children();
        self::assertInstanceOf(MdText::class, $inline[0]);
        self::assertSame('Heading', $inline[0]->text);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function setextLevels(): iterable
    {
        yield 'level 1' => ["Heading\n=======\n", 1];
        yield 'level 2' => ["Heading\n-------\n", 2];
        yield 'single equals' => ["Heading\n=\n", 1];
        yield 'single dash' => ["Heading\n-\n", 2];
    }

    public function testSetextHeadingSpanCoversTitleAndUnderline(): void
    {
        $markdown = "Heading\n=======\n";
        $heading = self::read($markdown)->children()[0];
        self::assertInstanceOf(MdHeading::class, $heading);
        self::assertSpan(0, \strlen("Heading\n======="), $heading);
    }

    public function testAtxHeadingSpanCoversTheWholeLine(): void
    {
        $markdown = "# Heading\n";
        $heading = self::read($markdown)->children()[0];
        self::assertSpan(0, \strlen('# Heading'), $heading);
    }
}
