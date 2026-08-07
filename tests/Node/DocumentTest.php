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

namespace Alto\Rst\Tests\Node;

use Alto\Rst\Node\BulletList;
use Alto\Rst\Node\ContainerNode;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\ListItem;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Section;
use Alto\Rst\Node\Text;
use Alto\Rst\Node\Title;
use Alto\Rst\Node\Transition;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContainerNode::class)]
#[CoversClass(Document::class)]
#[CoversClass(Node::class)]
final class DocumentTest extends TestCase
{
    public function testConstruction(): void
    {
        $paragraph = new Paragraph(ByteSpan::of(0, 5), new Text(ByteSpan::of(0, 5), 'Hello'));
        $document = new Document(ByteSpan::of(0, 5), [$paragraph]);

        self::assertSame(0, $document->span()->start);
        self::assertSame(5, $document->span()->length);
        self::assertSame([$paragraph], $document->children());
    }

    public function testEmptyDocument(): void
    {
        $document = new Document(ByteSpan::of(0, 0));

        self::assertSame([], $document->children());
        self::assertSame([], iterator_to_array($document->descendants(), false));
    }

    public function testDescendantsWalkDepthFirstInDocumentOrder(): void
    {
        $titleText = new Text(ByteSpan::of(0, 5), 'Intro');
        $title = new Title(ByteSpan::of(0, 5), $titleText);

        $paragraphText = new Text(ByteSpan::of(13, 5), 'First');
        $paragraph = new Paragraph(ByteSpan::of(13, 5), $paragraphText);

        $itemTextA = new Text(ByteSpan::of(22, 1), 'a');
        $itemParagraphA = new Paragraph(ByteSpan::of(22, 1), $itemTextA);
        $itemA = new ListItem(ByteSpan::of(20, 3), [$itemParagraphA]);

        $itemTextB = new Text(ByteSpan::of(26, 1), 'b');
        $itemParagraphB = new Paragraph(ByteSpan::of(26, 1), $itemTextB);
        $itemB = new ListItem(ByteSpan::of(24, 3), [$itemParagraphB]);

        $list = new BulletList(ByteSpan::of(20, 7), '-', [$itemA, $itemB]);

        $section = new Section(ByteSpan::of(0, 27), 1, $title, [$paragraph, $list], '=', false);
        $transition = new Transition(ByteSpan::of(29, 4));

        $document = new Document(ByteSpan::of(0, 33), [$section, $transition]);

        self::assertSame([
            $section,
            $title,
            $titleText,
            $paragraph,
            $paragraphText,
            $list,
            $itemA,
            $itemParagraphA,
            $itemTextA,
            $itemB,
            $itemParagraphB,
            $itemTextB,
            $transition,
        ], iterator_to_array($document->descendants(), false));
    }
}
