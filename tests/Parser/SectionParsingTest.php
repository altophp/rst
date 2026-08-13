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

use Alto\Rst\Node\LiteralBlock;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Section;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversNamespace;

#[CoversNamespace('Alto\\Rst\\Parser')]
final class SectionParsingTest extends ParserTestCase
{
    public function testUnderlineSection(): void
    {
        $result = self::parseRst("Title\n=====\n\nBody.\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $section = $children[0];
        self::assertInstanceOf(Section::class, $section);
        self::assertSame(1, $section->level);
        self::assertSame('=', $section->adornment);
        self::assertFalse($section->hasOverline);
        self::assertSame('Title', $section->title->text->text);
        self::assertSpan(0, 18, $section);
        self::assertSpan(0, 5, $section->title);

        $body = $section->body();
        self::assertCount(1, $body);
        self::assertInstanceOf(Paragraph::class, $body[0]);
        self::assertSame('Body.', $body[0]->text->text);
        self::assertSpan(13, 18, $body[0]);
        self::assertNoProblems($result);
    }

    public function testOverlineSection(): void
    {
        $result = self::parseRst("=====\nTitle\n=====\n\nBody.\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $section = $children[0];
        self::assertInstanceOf(Section::class, $section);
        self::assertTrue($section->hasOverline);
        self::assertSame('=', $section->adornment);
        self::assertSame('Title', $section->title->text->text);
        self::assertSpan(0, 24, $section);
        self::assertSpan(6, 11, $section->title);
        self::assertNoProblems($result);
    }

    public function testShortOverlineAndUnderlineReportTheWholeTitle(): void
    {
        $result = self::parseRst("====\nLong title\n====\n");

        self::assertSame(['section/short-adornment'], self::problemCodes($result));
        self::assertInstanceOf(Section::class, $result->document()->children()[0]);
    }

    public function testPreviouslySeenDeepStyleIsRejectedAtTheTopLevel(): void
    {
        $result = self::parseRst(
            "Top\n===\n\n"
            . "Sub\n---\n\n"
            . "Deep\n~~~~\n\n"
            . "Top two\n=======\n\n"
            . "Deep again\n~~~~~~~~~~\n",
        );

        self::assertSame(['section/inconsistent-style'], self::problemCodes($result));
        self::assertCount(2, $result->document()->children());
    }

    public function testAdornmentStylesGainLevelsInOrderOfFirstAppearance(): void
    {
        $result = self::parseRst("A\n=\n\nB\n-\n\nC\n=\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);

        $first = $children[0];
        self::assertInstanceOf(Section::class, $first);
        self::assertSame(1, $first->level);
        self::assertSame('=', $first->adornment);

        $nested = $first->body();
        self::assertCount(1, $nested);
        $second = $nested[0];
        self::assertInstanceOf(Section::class, $second);
        self::assertSame(2, $second->level);
        self::assertSame('-', $second->adornment);

        $third = $children[1];
        self::assertInstanceOf(Section::class, $third);
        self::assertSame(1, $third->level);
        self::assertSame('=', $third->adornment);
        self::assertNoProblems($result);
    }

    public function testOverlineAndUnderlineStylesAreDistinct(): void
    {
        $result = self::parseRst("=====\nOuter\n=====\n\nInner\n=====\n\nBody.\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $outer = $children[0];
        self::assertInstanceOf(Section::class, $outer);
        self::assertTrue($outer->hasOverline);
        self::assertSame(1, $outer->level);

        $body = $outer->body();
        self::assertCount(1, $body);
        $inner = $body[0];
        self::assertInstanceOf(Section::class, $inner);
        self::assertFalse($inner->hasOverline);
        self::assertSame(2, $inner->level);
        self::assertNoProblems($result);
    }

    public function testShortUnderlineIsAcceptedWithWarning(): void
    {
        $result = self::parseRst("A long title\n====\n\nBody.\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(Section::class, $children[0]);
        self::assertSame('A long title', $children[0]->title->text->text);
        self::assertSame(['section/short-adornment'], self::problemCodes($result));
    }

    public function testUnderlineShorterThanFourAndTitleIsOrdinaryText(): void
    {
        $result = self::parseRst("Hello\n==\n\nNext.\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(Paragraph::class, $children[0]);
        self::assertSame("Hello\n==", $children[0]->text->text);
        self::assertSame(['section/possible-underline'], self::problemCodes($result));
    }

    public function testInconsistentStyleOrderIsReportedAndPreserved(): void
    {
        $result = self::parseRst("T1\n==\n\nT2\n--\n\nT1 again\n========\n\nT3\n~~\n");
        $codes = self::problemCodes($result);

        self::assertSame(['section/inconsistent-style'], $codes);

        $children = $result->document()->children();
        self::assertCount(2, $children);
        $reopened = $children[1];
        self::assertInstanceOf(Section::class, $reopened);

        $body = $reopened->body();
        self::assertCount(1, $body);
        $recovered = $body[0];
        self::assertInstanceOf(Section::class, $recovered);
        self::assertSame(2, $recovered->level);
        self::assertSame('T3', $recovered->title->text->text);
    }

    public function testRejectedStyleDoesNotReserveALevelForTheNextStyle(): void
    {
        $rst = "Level 1\n=======\n\n"
            . "Level 2\n-------\n\n"
            . "Level 3\n.......\n\n"
            . "Level 4\n\"\"\"\"\"\"\"\n\n"
            . "Level 3 again\n.............\n\n"
            . "Rejected once\n'''''''''''''\n\nBody one.\n\n"
            . "Rejected twice\n''''''''''''''\n\nBody two.\n\n"
            . "Level 4 again\n\"\"\"\"\"\"\"\"\"\"\"\"\"\n\n"
            . "Level 5\n^^^^^^^\n";
        $result = self::parseRst($rst);

        $problems = $result->problems()->problems();
        self::assertSame(
            ['section/inconsistent-style', 'section/inconsistent-style'],
            self::problemCodes($result),
        );
        self::assertSame(
            [strpos($rst, 'Rejected once'), strpos($rst, 'Rejected twice')],
            array_map(static fn($problem): ?int => $problem->span?->start, $problems),
        );

        $level1 = $result->document()->children()[0];
        self::assertInstanceOf(Section::class, $level1);
        $level2 = $level1->body()[0];
        self::assertInstanceOf(Section::class, $level2);
        $level3 = $level2->body()[1];
        self::assertInstanceOf(Section::class, $level3);

        $recoveredOnce = $level3->body()[0];
        self::assertInstanceOf(Section::class, $recoveredOnce);
        self::assertSame('Rejected once', $recoveredOnce->title->text->text);
        $bodyOnce = $recoveredOnce->body()[0];
        self::assertInstanceOf(Paragraph::class, $bodyOnce);
        self::assertSame('Body one.', $bodyOnce->text->text);

        $recoveredTwice = $level3->body()[1];
        self::assertInstanceOf(Section::class, $recoveredTwice);
        self::assertSame('Rejected twice', $recoveredTwice->title->text->text);
        $bodyTwice = $recoveredTwice->body()[0];
        self::assertInstanceOf(Paragraph::class, $bodyTwice);
        self::assertSame('Body two.', $bodyTwice->text->text);

        $level4 = $level3->body()[2];
        self::assertInstanceOf(Section::class, $level4);
        $level5 = $level4->body()[0];
        self::assertInstanceOf(Section::class, $level5);
        self::assertSame(5, $level5->level);
        self::assertSame('^', $level5->adornment);
    }

    public function testEveryRejectedLevelJumpRemainsReportedDuringRecovery(): void
    {
        $rst = "Level 1\n=======\n\n"
            . "Level 2\n-------\n\n"
            . "Level 3\n.......\n\n"
            . "Level 4\n\"\"\"\"\"\"\"\n\n"
            . "Level 3 again\n.............\n\n";
        $expectedStarts = [];

        for ($i = 1; $i <= 7; ++$i) {
            $title = 'Rejected ' . $i;
            $expectedStarts[] = \strlen($rst);
            $rst .= $title . "\n" . str_repeat("'", \strlen($title)) . "\n\n";
        }

        $rst .= "Level 4 again\n\"\"\"\"\"\"\"\"\"\"\"\"\"\n";
        $result = self::parseRst($rst);
        $problems = $result->problems()->problems();

        self::assertSame(array_fill(0, 7, 'section/inconsistent-style'), self::problemCodes($result));
        self::assertSame(
            $expectedStarts,
            array_map(static fn($problem): ?int => $problem->span?->start, $problems),
        );

        $level1 = $result->document()->children()[0];
        self::assertInstanceOf(Section::class, $level1);
        $level2 = $level1->body()[0];
        self::assertInstanceOf(Section::class, $level2);
        $level3 = $level2->body()[1];
        self::assertInstanceOf(Section::class, $level3);
        $titles = [];

        foreach (array_slice($level3->body(), 0, 7) as $recovered) {
            self::assertInstanceOf(Section::class, $recovered);
            $titles[] = $recovered->title->text->text;
        }

        self::assertSame(
            ['Rejected 1', 'Rejected 2', 'Rejected 3', 'Rejected 4', 'Rejected 5', 'Rejected 6', 'Rejected 7'],
            $titles,
        );
    }

    public function testOverlineUnderlineMismatchBecomesALiteralRecoveryBlock(): void
    {
        $input = "====\nTitle\n----\n\nBody.\n";
        $result = self::parseRst($input);
        $children = $result->document()->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(LiteralBlock::class, $children[0]);
        self::assertSame("====\nTitle\n----", Source::fromString($input)->slice($children[0]->content));
        self::assertInstanceOf(Paragraph::class, $children[1]);
        self::assertSame('Body.', $children[1]->text->text);
        self::assertSame(['section/overline-underline-mismatch'], self::problemCodes($result));
    }

    public function testOverlineWithoutUnderlineBecomesALiteralRecoveryBlock(): void
    {
        $input = "====\nTitle\n\nBody.\n";
        $result = self::parseRst($input);
        $children = $result->document()->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(LiteralBlock::class, $children[0]);
        self::assertSame("====\nTitle", Source::fromString($input)->slice($children[0]->content));
        self::assertInstanceOf(Paragraph::class, $children[1]);
        self::assertSame(['section/missing-underline'], self::problemCodes($result));
    }

    public function testOverlineAndUnderlineLengthMismatchRecoversAsLiteral(): void
    {
        $result = self::parseRst("====\nTitle\n===\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(LiteralBlock::class, $children[0]);
        self::assertSame(['section/overline-underline-mismatch'], self::problemCodes($result));
    }

    public function testUtf8TitleLengthIsCountedInCharacters(): void
    {
        $result = self::parseRst("Résumé\n======\n\nBody.\n");

        self::assertNoProblems($result);
        $children = $result->document()->children();
        self::assertCount(1, $children);
        self::assertInstanceOf(Section::class, $children[0]);
        self::assertSame('Résumé', $children[0]->title->text->text);
    }

    public function testSectionTitlePreservesInlineMarkupInTheParsedSourceNode(): void
    {
        $result = self::parseRst("``widget``\n==========\n");
        $section = $result->document()->children()[0];

        self::assertInstanceOf(Section::class, $section);
        self::assertSame('``widget``', $section->title->text->text);
        self::assertSpan(0, \strlen('``widget``'), $section->title);
        self::assertNoProblems($result);
    }
}
