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

use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Render\HtmlRenderer;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlRenderer::class)]
final class HtmlRendererDefinitionListTest extends TestCase
{
    public function testDefinitionListUsesSemanticHtmlAndInlineMarkup(): void
    {
        $source = Source::fromString("term *one* : kind\n  body **strong**\n");
        $document = new BlockParser()->parse($source)->document();

        self::assertSame(
            "<dl>\n<dt>term <em>one</em> <span class=\"classifier-delimiter\">:</span> "
            . "<span class=\"classifier\">kind</span></dt>\n"
            . "<dd>\n<p>body <strong>strong</strong></p>\n</dd>\n</dl>\n",
            new HtmlRenderer()->render($document, $source),
        );
    }
}
