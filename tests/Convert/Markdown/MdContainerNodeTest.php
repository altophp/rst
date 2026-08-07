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
use Alto\Rst\Convert\Markdown\MdBlockQuote;
use Alto\Rst\Convert\Markdown\MdContainerNode;
use Alto\Rst\Convert\Markdown\MdLineCursor;
use Alto\Rst\Convert\Markdown\MdParagraph;
use Alto\Rst\Convert\Markdown\MdText;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MdContainerNode::class)]
#[CoversClass(BlockDraft::class)]
#[CoversClass(DocumentDraft::class)]
#[CoversClass(MarkdownBlockParser::class)]
#[CoversClass(MarkdownInlineItem::class)]
#[CoversClass(MarkdownInlineParser::class)]
#[CoversClass(MdLineCursor::class)]
final class MdContainerNodeTest extends MarkdownReaderTestCase
{
    public function testDescendantsWalksTheWholeSubtreeDepthFirst(): void
    {
        $document = self::read("> Outer.\n>\n> > Inner.\n");
        $outerQuote = $document->children()[0];
        self::assertInstanceOf(MdBlockQuote::class, $outerQuote);

        $kinds = [];

        foreach ($outerQuote->descendants() as $node) {
            $kinds[] = $node::class;
        }

        self::assertSame(
            [
                MdParagraph::class,
                MdText::class,
                MdBlockQuote::class,
                MdParagraph::class,
                MdText::class,
            ],
            $kinds,
        );
    }

    public function testLeafNodeHasNoDescendants(): void
    {
        $document = self::read("Plain text.\n");
        $paragraph = $document->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $text = $paragraph->children()[0];
        self::assertInstanceOf(MdText::class, $text);

        // MdText is a leaf (MdNode, not MdContainerNode); the paragraph's
        // own descendants stop at it.
        $descendants = iterator_to_array($paragraph->descendants());
        self::assertSame([$text], $descendants);
    }
}
