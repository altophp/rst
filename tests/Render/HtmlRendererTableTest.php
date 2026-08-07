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

use Alto\Rst\Node\Document;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Table;
use Alto\Rst\Node\TableCell;
use Alto\Rst\Node\TableRow;
use Alto\Rst\Node\TableStyle;
use Alto\Rst\Node\Text;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Render\HtmlRenderer;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlRenderer::class)]
final class HtmlRendererTableTest extends TestCase
{
    public function testHeadRowsUseTableHeaderCells(): void
    {
        $expected = "<table>\n"
            ."<thead>\n<tr>\n<th>\n<p>Alpha</p>\n</th>\n<th>\n<p>Beta</p>\n</th>\n</tr>\n</thead>\n"
            ."<tbody>\n<tr>\n<td>\n<p>one</p>\n</td>\n<td>\n<p>two</p>\n</td>\n</tr>\n</tbody>\n"
            ."</table>\n";

        self::assertSame($expected, self::renderRst("=====  =====\nAlpha  Beta\n=====  =====\none    two\n=====  =====\n"));
    }

    public function testTableWithoutHeadOmitsTheHeadSection(): void
    {
        $expected = "<table>\n"
            ."<tbody>\n<tr>\n<td>\n<p>one</p>\n</td>\n<td>\n<p>two</p>\n</td>\n</tr>\n</tbody>\n"
            ."</table>\n";

        self::assertSame($expected, self::renderRst("=====  =====\none    two\n=====  =====\n"));
    }

    public function testEmptyTableRendersNoRowSections(): void
    {
        self::assertSame("<table>\n</table>\n", self::renderRst("=====  =====\n=====  =====\n"));
    }

    public function testColumnSpanBecomesAColspanAttribute(): void
    {
        $html = self::renderRst(
            "=====  =====  ======\nName          Value\n------------  ------\n"
            ."First  Last   Number\n=====  =====  ======\nAda    Byron  1815\n=====  =====  ======\n",
        );

        self::assertStringContainsString("<th colspan=\"2\">\n<p>Name</p>\n</th>", $html);
        self::assertStringContainsString("<td>\n<p>Ada</p>\n</td>", $html);
    }

    public function testRowSpanBecomesARowspanAttribute(): void
    {
        $cell = new TableCell(ByteSpan::of(0, 1), [], 2, 3);
        $table = new Table(ByteSpan::of(0, 1), [], [new TableRow(ByteSpan::of(0, 1), [$cell])], [1], TableStyle::Simple);
        $document = new Document(ByteSpan::of(0, 1), [$table]);

        self::assertSame(
            "<table>\n<tbody>\n<tr>\n<td colspan=\"2\" rowspan=\"3\">\n</td>\n</tr>\n</tbody>\n</table>\n",
            new HtmlRenderer()->render($document, Source::fromString('x')),
        );
    }

    public function testCellContentIsEscaped(): void
    {
        $text = new Text(ByteSpan::of(0, 1), '<b>&"x"</b>');
        $cell = new TableCell(ByteSpan::of(0, 1), [new Paragraph(ByteSpan::of(0, 1), $text)]);
        $table = new Table(ByteSpan::of(0, 1), [], [new TableRow(ByteSpan::of(0, 1), [$cell])], [1], TableStyle::Simple);
        $document = new Document(ByteSpan::of(0, 1), [$table]);

        self::assertStringContainsString(
            '<p>&lt;b&gt;&amp;&quot;x&quot;&lt;/b&gt;</p>',
            new HtmlRenderer()->render($document, Source::fromString('x')),
        );
    }

    public function testStandaloneRowRendersAsAnInertComment(): void
    {
        $row = new TableRow(ByteSpan::of(0, 1));
        $document = new Document(ByteSpan::of(0, 1), [$row]);

        self::assertSame(
            "<!-- node: TableRow -->\n",
            new HtmlRenderer()->render($document, Source::fromString('x')),
        );
    }

    private static function renderRst(string $rst): string
    {
        $source = Source::fromString($rst);

        return new HtmlRenderer()->render(new BlockParser()->parse($source)->document(), $source);
    }
}
