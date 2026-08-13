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

namespace Alto\Rst\Tests\Render;

use Alto\Rst\Render\HtmlRenderer;
use Alto\Rst\Render\RenderState;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Inline rendering inside table cells. A multi-line cell slice is not
 * contiguous cell content (DECISIONS.md, "A table cell is a rectangle"),
 * so cell paragraphs run the inline pass per line segment: markup never
 * matches across a cell's line break, and the segments join with a
 * single space.
 */
#[CoversClass(HtmlRenderer::class)]
#[CoversClass(RenderState::class)]
final class HtmlRendererTableInlineTest extends TestCase
{
    public function testSingleLineCellContentRendersInlineMarkup(): void
    {
        $html = self::render(
            "=======  ========\nName     Value\n=======  ========\n*em*     ``lit``\n=======  ========\n",
        );

        self::assertStringContainsString("<td>\n<p><em>em</em></p>\n</td>", $html);
        self::assertStringContainsString("<td>\n<p><code>lit</code></p>\n</td>", $html);
    }

    public function testMarkupWrappingAcrossCellLinesDegradesToText(): void
    {
        $html = self::render(
            "========  ==========\ncol       text\n========  ==========\nfirst     *wrapped\n          markup*\n========  ==========\n",
        );

        self::assertStringContainsString('<p>*wrapped markup*</p>', $html);
        self::assertStringNotContainsString('<em>', $html);
    }

    public function testMultiLineCellSegmentsJoinWithASingleSpace(): void
    {
        $html = self::render(
            "========  ==========\ncol       text\n========  ==========\nfirst     two words\n          more here\n========  ==========\n",
        );

        self::assertStringContainsString('<p>two words more here</p>', $html);
    }

    public function testMultiLineGridCellsDoNotImportNeighbouringCellText(): void
    {
        $html = self::render(
            "+-----+-----+\n"
            . "| A   | B   |\n"
            . "+=====+=====+\n"
            . "| one | two |\n"
            . "| x   | y   |\n"
            . "+-----+-----+\n",
        );

        self::assertStringContainsString("<td>\n<p>one x</p>\n</td>", $html);
        self::assertStringContainsString("<td>\n<p>two y</p>\n</td>", $html);
        self::assertStringNotContainsString('one | two', $html);
    }

    public function testMarkupCompleteWithinOneCellLineStillRenders(): void
    {
        $html = self::render(
            "========  ==========\ncol       text\n========  ==========\nfirst     *one* line\n          **two** go\n========  ==========\n",
        );

        self::assertStringContainsString('<p><em>one</em> line <strong>two</strong> go</p>', $html);
    }

    public function testCellLinkGoesThroughThePolicy(): void
    {
        $html = self::render(
            "==========================  =====\nlink                        x\n==========================  =====\n`e <javascript:alert(1)>`_  y\n==========================  =====\n",
        );

        self::assertStringContainsString('<a href="">e</a>', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    private static function render(string $rst): string
    {
        $source = Source::fromString($rst);

        return new HtmlRenderer()->render(Rst::symfony()->parse($rst)->document(), $source);
    }
}
