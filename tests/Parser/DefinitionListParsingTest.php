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
use Alto\Rst\Node\DefinitionList;
use Alto\Rst\Node\Paragraph;
use PHPUnit\Framework\Attributes\CoversNamespace;

#[CoversNamespace('Alto\\Rst\\Parser')]
final class DefinitionListParsingTest extends ParserTestCase
{
    public function testTermsClassifiersAndNestedBlocks(): void
    {
        $rst = "term : classifier : other\n  body with *markup*\n\nnext\n  - one\n  - two\n";
        $result = self::parseRst($rst);
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $list = $children[0];
        self::assertInstanceOf(DefinitionList::class, $list);
        self::assertCount(2, $list->children());
        self::assertSpan(0, \strlen($rst) - 1, $list);

        $first = $list->children()[0];
        self::assertSame('term', $first->term->text);
        self::assertSame(['classifier', 'other'], array_map(static fn ($text): string => $text->text, $first->classifiers));
        self::assertCount(1, $first->definition());
        self::assertInstanceOf(Paragraph::class, $first->definition()[0]);
        self::assertSame('body with *markup*', $first->definition()[0]->text->text);

        $second = $list->children()[1];
        self::assertSame('next', $second->term->text);
        self::assertInstanceOf(BulletList::class, $second->definition()[0]);
        self::assertNoProblems($result);
    }

    public function testEscapedClassifierDelimiterStaysInTheTerm(): void
    {
        $result = self::parseRst("term \\ : value : classifier\n  body\n");
        $list = $result->document()->children()[0];

        self::assertInstanceOf(DefinitionList::class, $list);
        self::assertSame('term \\ : value', $list->children()[0]->term->text);
        self::assertSame('classifier', $list->children()[0]->classifiers[0]->text);
        self::assertNoProblems($result);
    }

    public function testMultiLineParagraphStillReportsUnexpectedIndentation(): void
    {
        $result = self::parseRst("first line\nsecond line\n  indented\n");

        self::assertSame(['parser/unexpected-indentation'], self::problemCodes($result));
    }
}
