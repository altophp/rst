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

use Alto\Rst\Node\BulletList;
use Alto\Rst\Node\Paragraph;
use PHPUnit\Framework\Attributes\CoversNamespace;

#[CoversNamespace('Alto\\Rst\\Parser')]
final class BulletListParsingTest extends ParserTestCase
{
    public function testTabAfterMarkerUsesTheNextEightColumnStop(): void
    {
        $result = self::parseRst("-\titem\n-\tnext\n");
        $list = $result->document()->children()[0];

        self::assertInstanceOf(BulletList::class, $list);
        self::assertCount(2, $list->children());
    }

    public function testSimpleList(): void
    {
        $result = self::parseRst("- one\n- two\n- three\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $list = $children[0];
        self::assertInstanceOf(BulletList::class, $list);
        self::assertSame('-', $list->marker);
        self::assertCount(3, $list->children());
        self::assertSpan(0, 19, $list);

        $first = $list->children()[0];
        self::assertSpan(0, 5, $first);
        $paragraph = $first->children()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertSame('one', $paragraph->text->text);
        self::assertSpan(2, 5, $paragraph);
        self::assertNoProblems($result);
    }

    public function testItemWithContinuationAndSecondParagraph(): void
    {
        $result = self::parseRst("- first line\n  continued\n\n  second paragraph\n- next item\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $list = $children[0];
        self::assertInstanceOf(BulletList::class, $list);
        self::assertCount(2, $list->children());

        $item = $list->children()[0];
        self::assertCount(2, $item->children());
        $lead = $item->children()[0];
        self::assertInstanceOf(Paragraph::class, $lead);
        self::assertSame("first line\n  continued", $lead->text->text);
        self::assertNoProblems($result);
    }

    public function testNestedListByIndentation(): void
    {
        $result = self::parseRst("- outer\n\n  - inner one\n  - inner two\n\n- outer two\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $list = $children[0];
        self::assertInstanceOf(BulletList::class, $list);
        self::assertCount(2, $list->children());

        $itemChildren = $list->children()[0]->children();
        self::assertCount(2, $itemChildren);
        self::assertInstanceOf(Paragraph::class, $itemChildren[0]);
        $nested = $itemChildren[1];
        self::assertInstanceOf(BulletList::class, $nested);
        self::assertCount(2, $nested->children());
        self::assertNoProblems($result);
    }

    public function testMarkerChangeAfterBlankLineMakesTwoListsWithoutProblems(): void
    {
        $result = self::parseRst("- one\n\n* two\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(BulletList::class, $children[0]);
        self::assertInstanceOf(BulletList::class, $children[1]);
        self::assertSame('-', $children[0]->marker);
        self::assertSame('*', $children[1]->marker);
        self::assertNoProblems($result);
    }

    public function testMarkerChangeWithoutBlankLineIsReported(): void
    {
        $result = self::parseRst("- one\n* two\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(BulletList::class, $children[0]);
        self::assertInstanceOf(BulletList::class, $children[1]);
        self::assertSame(['list/mixed-markers'], self::problemCodes($result));
    }

    public function testListEndingWithoutBlankLineIsReported(): void
    {
        $result = self::parseRst("- item\nplain text\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(BulletList::class, $children[0]);
        self::assertInstanceOf(Paragraph::class, $children[1]);
        self::assertSame(['list/missing-blank-line'], self::problemCodes($result));
    }

    public function testEmptyItem(): void
    {
        $result = self::parseRst("- one\n-\n- three\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $list = $children[0];
        self::assertInstanceOf(BulletList::class, $list);
        self::assertCount(3, $list->children());
        self::assertSame([], $list->children()[1]->children());
    }

    public function testEmptyMarkerWithIndentedContent(): void
    {
        $result = self::parseRst("-\n  content\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $list = $children[0];
        self::assertInstanceOf(BulletList::class, $list);
        self::assertCount(1, $list->children());

        $itemChildren = $list->children()[0]->children();
        self::assertCount(1, $itemChildren);
        self::assertInstanceOf(Paragraph::class, $itemChildren[0]);
        self::assertSame('content', $itemChildren[0]->text->text);
        self::assertNoProblems($result);
    }

    public function testUnicodeBulletMarker(): void
    {
        $result = self::parseRst("\u{2022} bullet one\n\u{2022} bullet two\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $list = $children[0];
        self::assertInstanceOf(BulletList::class, $list);
        self::assertSame("\u{2022}", $list->marker);
        self::assertCount(2, $list->children());
        self::assertNoProblems($result);
    }
}
