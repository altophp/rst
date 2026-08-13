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

use Alto\Rst\Node\EnumeratedList;
use Alto\Rst\Node\EnumerationStyle;
use Alto\Rst\Node\Paragraph;
use PHPUnit\Framework\Attributes\CoversNamespace;

#[CoversNamespace('Alto\\Rst\\Parser')]
final class EnumeratedListParsingTest extends ParserTestCase
{
    public function testArabicPeriodList(): void
    {
        $result = self::parseRst("1. one\n2. two\n3. three\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $list = $children[0];
        self::assertInstanceOf(EnumeratedList::class, $list);
        self::assertSame(EnumerationStyle::Arabic, $list->style);
        self::assertSame(1, $list->start);
        self::assertCount(3, $list->children());
        self::assertSpan(0, 22, $list);
        self::assertNoProblems($result);
    }

    public function testLowerRomanListCanStartWithI(): void
    {
        $result = self::parseRst("i. one\nii. two\n");
        $list = $result->document()->children()[0];

        self::assertInstanceOf(EnumeratedList::class, $list);
        self::assertSame(EnumerationStyle::LowerRoman, $list->style);
        self::assertSame(1, $list->start);
        self::assertCount(2, $list->children());
        self::assertNoProblems($result);
    }

    public function testInvalidRomanEnumeratorStaysParagraphText(): void
    {
        $result = self::parseRst("iiii. invalid\n");

        self::assertInstanceOf(Paragraph::class, $result->document()->children()[0]);
        self::assertSame('iiii. invalid', $result->document()->children()[0]->text->text);
    }

    public function testStartValueComesFromTheFirstItem(): void
    {
        $result = self::parseRst("3. three\n4. four\n5. five\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $list = $children[0];
        self::assertInstanceOf(EnumeratedList::class, $list);
        self::assertSame(3, $list->start);
        self::assertCount(3, $list->children());
        self::assertNoProblems($result);
    }

    public function testAutoEnumeratorResolvesToArabicFromOne(): void
    {
        $result = self::parseRst("#. one\n#. two\n#. three\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $list = $children[0];
        self::assertInstanceOf(EnumeratedList::class, $list);
        self::assertSame(EnumerationStyle::Arabic, $list->style);
        self::assertSame(1, $list->start);
        self::assertCount(3, $list->children());
        self::assertNoProblems($result);
    }

    public function testNumberedItemMayContinueWithAutoEnumerator(): void
    {
        $result = self::parseRst("1. one\n#. two\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $list = $children[0];
        self::assertInstanceOf(EnumeratedList::class, $list);
        self::assertCount(2, $list->children());
        self::assertNoProblems($result);
    }

    public function testFormatMismatchOnSecondLineKeepsTheRunAsAParagraph(): void
    {
        $result = self::parseRst("1. one\n2) two\n3) three\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(Paragraph::class, $children[0]);
        self::assertSame("1. one\n2) two\n3) three", $children[0]->text->text);
        self::assertNoProblems($result);
    }

    public function testNonConsecutiveSecondItemKeepsTheRunAsAParagraph(): void
    {
        $result = self::parseRst("1. one\n3. three\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(Paragraph::class, $children[0]);
        self::assertSame("1. one\n3. three", $children[0]->text->text);
        self::assertNoProblems($result);
    }

    public function testParenthesizedAndParenFormats(): void
    {
        $result = self::parseRst("(1) one\n(2) two\n\nAnd:\n\n1) uno\n2) dos\n");
        $children = $result->document()->children();

        self::assertCount(3, $children);
        self::assertInstanceOf(EnumeratedList::class, $children[0]);
        self::assertCount(2, $children[0]->children());
        self::assertInstanceOf(EnumeratedList::class, $children[2]);
        self::assertCount(2, $children[2]->children());
        self::assertNoProblems($result);
    }

    public function testUnindentedSecondLineMakesAParagraphNotAList(): void
    {
        $result = self::parseRst("1. looks like a list\nbut is a paragraph\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $paragraph = $children[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertSame("1. looks like a list\nbut is a paragraph", $paragraph->text->text);
        self::assertNoProblems($result);
    }

    public function testAlphabeticEnumeratorsPreserveStyleAndStart(): void
    {
        $result = self::parseRst("c. three\nd. four\n\nC. three\nD. four\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(EnumeratedList::class, $children[0]);
        self::assertSame(EnumerationStyle::LowerAlpha, $children[0]->style);
        self::assertSame(3, $children[0]->start);
        self::assertInstanceOf(EnumeratedList::class, $children[1]);
        self::assertSame(EnumerationStyle::UpperAlpha, $children[1]->style);
        self::assertSame(3, $children[1]->start);
        self::assertNoProblems($result);
    }

    public function testRomanAndAutoEnumerators(): void
    {
        $result = self::parseRst("iv. four\nv. five\n\n(IV) four\n(V) five\n\n#. one\n#. two\n");
        $children = $result->document()->children();

        self::assertCount(3, $children);
        self::assertInstanceOf(EnumeratedList::class, $children[0]);
        self::assertSame(EnumerationStyle::LowerRoman, $children[0]->style);
        self::assertSame(4, $children[0]->start);
        self::assertInstanceOf(EnumeratedList::class, $children[1]);
        self::assertSame(EnumerationStyle::UpperRoman, $children[1]->style);
        self::assertSame(4, $children[1]->start);
        self::assertInstanceOf(EnumeratedList::class, $children[2]);
        self::assertSame(EnumerationStyle::Arabic, $children[2]->style);
        self::assertNoProblems($result);
    }

    public function testAlphabeticSequenceKeepsIAsNinthLetter(): void
    {
        $result = self::parseRst("h. eight\ni. nine\nj. ten\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(EnumeratedList::class, $children[0]);
        self::assertSame(EnumerationStyle::LowerAlpha, $children[0]->style);
        self::assertSame(8, $children[0]->start);
        self::assertCount(3, $children[0]->children());
        self::assertNoProblems($result);
    }

    public function testUpperAlphabeticParenListAllowsIndentedContinuation(): void
    {
        $result = self::parseRst("A) First item\n   continued\nB) Second item\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(EnumeratedList::class, $children[0]);
        self::assertSame(EnumerationStyle::UpperAlpha, $children[0]->style);
        self::assertSame(1, $children[0]->start);
        self::assertCount(2, $children[0]->children());
        self::assertNoProblems($result);
    }

    public function testMultiLineItemContent(): void
    {
        $result = self::parseRst("1. first item\n   still first\n2. second\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $list = $children[0];
        self::assertInstanceOf(EnumeratedList::class, $list);
        self::assertCount(2, $list->children());

        $lead = $list->children()[0]->children()[0];
        self::assertInstanceOf(Paragraph::class, $lead);
        self::assertSame("first item\n   still first", $lead->text->text);
        self::assertNoProblems($result);
    }

    public function testMultiDigitMarkerRebasesContinuationAtTheTextColumn(): void
    {
        $result = self::parseRst(
            "10.  first line\n"
            . "     aligned continuation\n"
            . "      unexpected indentation\n"
            . "11.  next\n",
        );
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $list = $children[0];
        self::assertInstanceOf(EnumeratedList::class, $list);
        self::assertCount(2, $list->children());
        $first = $list->children()[0]->children();
        self::assertCount(2, $first);
        self::assertInstanceOf(Paragraph::class, $first[0]);
        self::assertSame("first line\n     aligned continuation", $first[0]->text->text);
        self::assertSame(['parser/unexpected-indentation'], self::problemCodes($result));
        self::assertSame(
            strpos("10.  first line\n     aligned continuation\n      unexpected indentation\n11.  next\n", '      unexpected'),
            $result->problems()->problems()[0]->span?->start,
        );
    }
}
