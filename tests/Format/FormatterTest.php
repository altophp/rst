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

namespace Alto\Rst\Tests\Format;

use Alto\Rst\Format\FormatOptions;
use Alto\Rst\Format\Formatter;
use Alto\Rst\Node\BulletList;
use Alto\Rst\Node\Section;
use Alto\Rst\Operation\SourcePatch;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Rst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Formatter::class)]
final class FormatterTest extends TestCase
{
    public function testNormalizesUnderlineLengthAndPreservesSurroundingBytes(): void
    {
        $source = "Long title\r\n================  \r\n\r\nBody.\r\n";
        $result = new Formatter()->format($source, new FormatOptions(bulletMarker: null));

        self::assertSame("Long title\r\n==========  \r\n\r\nBody.\r\n", $result->bytes);
        self::assertCount(1, $result->patches);
        self::assertSame(12, $result->patches[0]->span->start);
        self::assertSame(16, $result->patches[0]->span->length);
        self::assertSame('==========', $result->patches[0]->replacement);
        self::assertSame(0, $result->skippedSectionTitles);
        $this->assertBytesOutsidePatchesUnchanged($source, $result->bytes, $result->patches);
    }

    public function testNormalizesBothOverlineAndUnderlineWithBareCr(): void
    {
        $source = "========\rTitle\r========\r";
        $result = new Formatter()->format($source, new FormatOptions(bulletMarker: null));

        self::assertSame("=====\rTitle\r=====\r", $result->bytes);
        self::assertCount(2, $result->patches);
        self::assertSame('=====', $result->patches[0]->replacement);
        self::assertSame('=====', $result->patches[1]->replacement);
        $this->assertBytesOutsidePatchesUnchanged($source, $result->bytes, $result->patches);
    }

    #[DataProvider('unicodeTitles')]
    public function testMeasuresUtf8TitlesInDisplayColumns(string $title, int $columns): void
    {
        if (!\function_exists('mb_strwidth')) {
            self::markTestSkipped('Unicode display width requires mbstring.');
        }

        $source = $title . "\n==========\n";
        $result = new Formatter()->format($source, new FormatOptions(bulletMarker: null));

        self::assertSame($title . "\n" . str_repeat('=', $columns) . "\n", $result->bytes);
        self::assertSame(0, $result->skippedSectionTitles);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function unicodeTitles(): iterable
    {
        yield 'accented latin' => ['Résumé', 6];
        yield 'wide characters' => ['文档', 4];
    }

    public function testSkipsAnInvalidUtf8Title(): void
    {
        $source = "Bad\xFFtitle\n=========\n";
        $result = new Formatter()->format($source, new FormatOptions(bulletMarker: null));

        self::assertSame($source, $result->bytes);
        self::assertSame([], $result->patches);
        self::assertSame(1, $result->skippedSectionTitles);
    }

    public function testSkipsTitlesWhoseDisplayWidthDependsOnGraphemeComposition(): void
    {
        $source = "Cafe\u{0301}\n========\n";
        $result = new Formatter()->format($source, new FormatOptions(bulletMarker: null));

        self::assertSame($source, $result->bytes);
        self::assertSame([], $result->patches);
        self::assertSame(1, $result->skippedSectionTitles);
    }

    public function testNormalizesAsciiBulletMarkersAtEveryNestingLevel(): void
    {
        $source = "* outer\n\n  + inner one\n  + inner two\n\n* outer two\n";
        $result = new Formatter()->format($source);

        self::assertSame("- outer\n\n  - inner one\n  - inner two\n\n- outer two\n", $result->bytes);
        self::assertCount(4, $result->patches);
        self::assertSame(0, $result->skippedBulletLists);

        $document = Rst::docutils()->parse($result->bytes)->document();
        $outer = $document->children()[0];
        self::assertInstanceOf(BulletList::class, $outer);
        self::assertCount(2, $outer->children());
        self::assertInstanceOf(BulletList::class, $outer->children()[0]->children()[1]);
    }

    public function testPreservesUtf8BomAndCrlfWhileChangingMarkers(): void
    {
        $source = "\xEF\xBB\xBF* one\r\n* two\r\n";
        $result = new Formatter()->format($source);

        self::assertSame("\xEF\xBB\xBF- one\r\n- two\r\n", $result->bytes);
        self::assertSame(3, $result->patches[0]->span->start);
        $this->assertBytesOutsidePatchesUnchanged($source, $result->bytes, $result->patches);
    }

    public function testSkipsUnicodeBulletsAndAdjacentListsThatCouldMerge(): void
    {
        $source = "\u{2022} unicode\n\n* first\n\n+ second\n";
        $result = new Formatter()->format($source);

        self::assertSame($source, $result->bytes);
        self::assertSame([], $result->patches);
        self::assertSame(3, $result->skippedBulletLists);
    }

    public function testCanDisableEitherPass(): void
    {
        $source = "Title\n========\n\n* item\n";
        $result = new Formatter()->format(
            $source,
            new FormatOptions(normalizeSectionAdornments: false, bulletMarker: null, lineWidth: null),
        );

        self::assertSame($source, $result->bytes);
        self::assertFalse($result->changed());
    }

    public function testWrapsSimpleParagraphsOnlyWhenRequested(): void
    {
        $source = 'This paragraph contains enough ordinary prose to exceed the deliberately short configured line width.' . "\n";
        $formatter = new Formatter();

        $default = $formatter->format(
            $source,
            new FormatOptions(normalizeSectionAdornments: false, bulletMarker: null),
        );
        $wrapped = $formatter->format(
            $source,
            new FormatOptions(
                normalizeSectionAdornments: false,
                bulletMarker: null,
                lineWidth: 40,
            ),
        );

        self::assertSame($source, $default->bytes);
        self::assertSame(
            "This paragraph contains enough ordinary\n"
            . "prose to exceed the deliberately short\n"
            . "configured line width.\n",
            $wrapped->bytes,
        );
        self::assertCount(1, $wrapped->patches);
    }

    public function testAlignsStableSimpleTablesByDefaultAndCanDisableThePass(): void
    {
        $source = "==========  ==========\nfirst       one\nlonger      two\n==========  ==========\n";
        $formatter = new Formatter();

        self::assertSame(
            "======  ===\nfirst   one\nlonger  two\n======  ===\n",
            $formatter->format($source)->bytes,
        );
        self::assertSame(
            $source,
            $formatter->format(
                $source,
                new FormatOptions(
                    normalizeSectionAdornments: false,
                    bulletMarker: null,
                    alignSimpleTables: false,
                ),
            )->bytes,
        );
    }

    public function testFormattingIsIdempotentAndDoesNotMutateTheOriginalRom(): void
    {
        $source = "Title\n========\n\n* item\n";
        $original = Rst::docutils()->parse($source)->document();
        $originalSection = $original->children()[0];
        self::assertInstanceOf(Section::class, $originalSection);
        $originalList = $originalSection->body()[0];
        self::assertInstanceOf(BulletList::class, $originalList);

        $formatter = new Formatter();
        $first = $formatter->format($source);
        $second = $formatter->format($first->bytes);

        self::assertSame("Title\n=====\n\n- item\n", $first->bytes);
        self::assertSame($first->bytes, $second->bytes);
        self::assertSame([], $second->patches);
        self::assertSame('=', $originalSection->adornment);
        self::assertSame('*', $originalList->marker);
    }

    public function testUsesTheSelectedProfileForTheStructuralGuard(): void
    {
        $source = "* item\n";
        $result = new Formatter()->format($source, profile: Profile::symfony());

        self::assertSame("- item\n", $result->bytes);
    }

    public function testLeavesUnrelatedSourceFormsByteForByteUntouched(): void
    {
        $source = <<<'RST'
            .. comment stays

            Paragraph  with  spacing.

            ========
            Heading
            ========

            * item

            +-------+
            | table |
            +-------+
            RST;

        $result = new Formatter()->format($source);

        self::assertSame(
            <<<'RST'
                .. comment stays

                Paragraph  with  spacing.

                =======
                Heading
                =======

                - item

                +-------+
                | table |
                +-------+
                RST,
            $result->bytes,
        );
    }

    /**
     * @param list<SourcePatch> $patches
     */
    private function assertBytesOutsidePatchesUnchanged(string $before, string $after, array $patches): void
    {
        $beforeCursor = 0;
        $afterCursor = 0;

        foreach ($patches as $patch) {
            $unchangedLength = $patch->span->start - $beforeCursor;

            self::assertSame(
                substr($before, $beforeCursor, $unchangedLength),
                substr($after, $afterCursor, $unchangedLength),
            );

            $beforeCursor = $patch->span->end();
            $afterCursor += $unchangedLength + \strlen($patch->replacement);
        }

        self::assertSame(substr($before, $beforeCursor), substr($after, $afterCursor));
    }
}
