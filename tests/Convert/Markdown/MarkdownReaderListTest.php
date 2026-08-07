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
use Alto\Rst\Convert\Markdown\MdLineCursor;
use Alto\Rst\Convert\Markdown\MdList;
use Alto\Rst\Convert\Markdown\MdListDelimiter;
use Alto\Rst\Convert\Markdown\MdListItem;
use Alto\Rst\Convert\Markdown\MdParagraph;
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
#[CoversClass(MdList::class)]
#[CoversClass(MdListItem::class)]
#[CoversClass(MdListDelimiter::class)]
final class MarkdownReaderListTest extends MarkdownReaderTestCase
{
    #[DataProvider('bulletMarkers')]
    public function testBulletListMarker(string $marker): void
    {
        $list = self::read("{$marker} one\n{$marker} two\n")->children()[0];
        self::assertInstanceOf(MdList::class, $list);
        self::assertFalse($list->ordered);
        self::assertSame($marker, $list->bulletMarker);
        self::assertCount(2, $list->children());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bulletMarkers(): iterable
    {
        yield 'hyphen' => ['-'];
        yield 'asterisk' => ['*'];
        yield 'plus' => ['+'];
    }

    public function testBulletListItemTextIsParagraphInline(): void
    {
        $list = self::read("- item *one*\n")->children()[0];
        self::assertInstanceOf(MdList::class, $list);
        $item = $list->children()[0];
        self::assertInstanceOf(MdListItem::class, $item);
        $paragraph = $item->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
    }

    #[DataProvider('orderedDelimiters')]
    public function testOrderedListDelimiter(string $delimiter, MdListDelimiter $expected): void
    {
        $list = self::read("1{$delimiter} one\n2{$delimiter} two\n")->children()[0];
        self::assertInstanceOf(MdList::class, $list);
        self::assertTrue($list->ordered);
        self::assertSame($expected, $list->delimiter);
        self::assertSame(1, $list->start);
    }

    /**
     * @return iterable<string, array{string, MdListDelimiter}>
     */
    public static function orderedDelimiters(): iterable
    {
        yield 'period' => ['.', MdListDelimiter::Period];
        yield 'paren' => [')', MdListDelimiter::Paren];
    }

    public function testOrderedListStartValue(): void
    {
        $list = self::read("5. five\n6. six\n")->children()[0];
        self::assertInstanceOf(MdList::class, $list);
        self::assertSame(5, $list->start);
        self::assertCount(2, $list->children());
    }

    public function testChangingBulletMarkerStartsANewList(): void
    {
        $children = self::read("- one\n* two\n")->children();
        self::assertCount(2, $children);
        self::assertInstanceOf(MdList::class, $children[0]);
        self::assertInstanceOf(MdList::class, $children[1]);
        self::assertSame('-', $children[0]->bulletMarker);
        self::assertSame('*', $children[1]->bulletMarker);
    }

    public function testChangingOrderedDelimiterStartsANewList(): void
    {
        $children = self::read("1. one\n2) two\n")->children();
        self::assertCount(2, $children);
        self::assertInstanceOf(MdList::class, $children[0]);
        self::assertInstanceOf(MdList::class, $children[1]);
    }

    public function testTightListIsTight(): void
    {
        $list = self::read("- one\n- two\n")->children()[0];
        self::assertInstanceOf(MdList::class, $list);
        self::assertTrue($list->tight);
    }

    public function testBlankLineBetweenItemsMakesTheListLoose(): void
    {
        $list = self::read("- one\n\n- two\n")->children()[0];
        self::assertInstanceOf(MdList::class, $list);
        self::assertFalse($list->tight);
        self::assertCount(2, $list->children());
    }

    public function testBlankLineAfterTheOnlyItemDoesNotFollowedByMoreItemsStaysTight(): void
    {
        $children = self::read("- one\n\nParagraph after.\n")->children();
        self::assertInstanceOf(MdList::class, $children[0]);
        self::assertTrue($children[0]->tight);
        self::assertInstanceOf(MdParagraph::class, $children[1]);
    }

    public function testMultiLineItemContentIsPartOfTheSameItem(): void
    {
        $list = self::read("- one\n  still one\n- two\n")->children()[0];
        self::assertInstanceOf(MdList::class, $list);
        $items = $list->children();
        self::assertCount(2, $items);
        $paragraph = $items[0]->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $inline = $paragraph->children()[0];
        self::assertInstanceOf(MdText::class, $inline);
        self::assertSame('one still one', $inline->text);
    }

    public function testNestedList(): void
    {
        $list = self::read("- outer\n  - inner\n")->children()[0];
        self::assertInstanceOf(MdList::class, $list);
        $outerItem = $list->children()[0];
        self::assertInstanceOf(MdListItem::class, $outerItem);
        $itemChildren = $outerItem->children();
        self::assertCount(2, $itemChildren);
        self::assertInstanceOf(MdParagraph::class, $itemChildren[0]);
        self::assertInstanceOf(MdList::class, $itemChildren[1]);
    }

    public function testEmptyListItem(): void
    {
        $list = self::read("-\n- two\n")->children()[0];
        self::assertInstanceOf(MdList::class, $list);
        $items = $list->children();
        self::assertCount(2, $items);
        self::assertSame([], $items[0]->children());
    }

    public function testEmptyListItemFollowedByBlankLineThenUnrelatedParagraph(): void
    {
        $children = self::read("-\n\nParagraph.\n")->children();
        self::assertCount(2, $children);
        self::assertInstanceOf(MdList::class, $children[0]);
        self::assertSame([], $children[0]->children()[0]->children());
        self::assertInstanceOf(MdParagraph::class, $children[1]);
    }

    public function testListItemWithLoosenessFromAnInternalBlankLine(): void
    {
        $list = self::read("- one\n\n  still one\n")->children()[0];
        self::assertInstanceOf(MdList::class, $list);
        self::assertFalse($list->tight);
        $paragraphs = $list->children()[0]->children();
        self::assertCount(2, $paragraphs);
    }

    public function testListSpanCoversAllItems(): void
    {
        $markdown = "- one\n- two\n";
        $list = self::read($markdown)->children()[0];
        self::assertSpan(0, \strlen('- one'."\n".'- two'), $list);
    }

    public function testFiveOrMoreSpacesAfterMarkerIsTreatedAsOneSpaceThenIndentation(): void
    {
        $list = self::read("-     code\n")->children()[0];
        self::assertInstanceOf(MdList::class, $list);
        $item = $list->children()[0];
        $block = $item->children()[0];
        self::assertNotInstanceOf(MdParagraph::class, $block);
    }

    public function testThematicBreakInsideSpacedDashesIsNotABulletList(): void
    {
        $node = self::read("- - -\n")->children()[0];
        self::assertNotInstanceOf(MdList::class, $node);
    }

    public function testTabAfterAShortMarkerOvershootsAndBecomesIndentation(): void
    {
        $list = self::read("-\tone\n")->children()[0];
        self::assertInstanceOf(MdList::class, $list);
        $item = $list->children()[0];
        self::assertInstanceOf(MdListItem::class, $item);
        $block = $item->children()[0];
        self::assertNotInstanceOf(MdParagraph::class, $block);
    }

    public function testTabRightAtAStopIsFullyConsumedByTheMarkerColumn(): void
    {
        $list = self::read("123456.\tcode\n")->children()[0];
        self::assertInstanceOf(MdList::class, $list);
        $item = $list->children()[0];
        $paragraph = $item->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $text = $paragraph->children()[0];
        self::assertInstanceOf(MdText::class, $text);
        self::assertSame('code', $text->text);
    }

    public function testBulletListEndsWhenTheNextLineIsIndentedButNotEnoughToContinueTheItem(): void
    {
        $children = self::read("   - one\n    x\n")->children();
        self::assertCount(2, $children);
        self::assertInstanceOf(MdList::class, $children[0]);
        $paragraph = $children[0]->children()[0]->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        self::assertNotInstanceOf(MdList::class, $children[1]);
    }

    public function testOrderedListEndsWhenTheNextLineIsIndentedButNotEnoughToContinueTheItem(): void
    {
        $children = self::read("   1. one\n    x\n")->children();
        self::assertCount(2, $children);
        self::assertInstanceOf(MdList::class, $children[0]);
        self::assertNotInstanceOf(MdList::class, $children[1]);
    }
}
