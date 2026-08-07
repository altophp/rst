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
use Alto\Rst\Node\CitationDefinition;
use Alto\Rst\Node\Comment;
use Alto\Rst\Node\FootnoteDefinition;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\SubstitutionDefinition;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversNamespace('Alto\\Rst\\Parser')]
final class ReferenceDefinitionParsingTest extends ParserTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function footnoteLabels(): iterable
    {
        yield 'numbered' => ['1', '1'];
        yield 'auto numbered' => ['#', '#'];
        yield 'labelled auto numbered' => ['#note', '#note'];
        yield 'auto symbol' => ['*', '*'];
    }

    #[DataProvider('footnoteLabels')]
    public function testFootnoteLabelsAreClassifiedAndPreserved(string $sourceLabel, string $expectedLabel): void
    {
        $result = self::parseRst(sprintf(".. [%s] Body.\n", $sourceLabel));
        $definition = $result->document()->children()[0];

        self::assertInstanceOf(FootnoteDefinition::class, $definition);
        self::assertSame($expectedLabel, $definition->label);
        self::assertCount(1, $definition->body);
        self::assertInstanceOf(Paragraph::class, $definition->body[0]);
        self::assertSame('Body.', $definition->body[0]->text->text);
        self::assertNoProblems($result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function citationLabels(): iterable
    {
        yield 'alphabetic' => ['CIT2002'];
        yield 'mixed numeric' => ['1a'];
        yield 'punctuated simple name' => ['RFC-2119'];
    }

    #[DataProvider('citationLabels')]
    public function testOtherSimpleLabelsAreCitations(string $label): void
    {
        $result = self::parseRst(sprintf(".. [%s] Citation body.\n", $label));
        $definition = $result->document()->children()[0];

        self::assertInstanceOf(CitationDefinition::class, $definition);
        self::assertSame($label, $definition->label);
        self::assertCount(1, $definition->body);
        self::assertNoProblems($result);
    }

    public function testFirstLineAndIndentedContinuationBecomeOneParagraphWithOriginalOffsets(): void
    {
        $input = ".. [1] First line.\n   continuation.\n\nAfter.\n";
        $result = self::parseRst($input);
        $children = $result->document()->children();
        $definition = $children[0];

        self::assertCount(2, $children);
        self::assertInstanceOf(FootnoteDefinition::class, $definition);
        self::assertSpan(0, 35, $definition);
        self::assertCount(1, $definition->body);
        $paragraph = $definition->body[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertSpan(7, 35, $paragraph);
        self::assertSame("First line.\n   continuation.", $paragraph->text->text);
        self::assertNoProblems($result);
    }

    public function testMarkerOnlyDefinitionParsesItsIndentedBody(): void
    {
        $result = self::parseRst(".. [#]\n   Body.\n");
        $definition = $result->document()->children()[0];

        self::assertInstanceOf(FootnoteDefinition::class, $definition);
        self::assertSame('#', $definition->label);
        self::assertCount(1, $definition->body);
        self::assertInstanceOf(Paragraph::class, $definition->body[0]);
        self::assertSame('Body.', $definition->body[0]->text->text);
        self::assertNoProblems($result);
    }

    public function testDefinitionBodyContainsRealNestedBlocks(): void
    {
        $input = <<<'RST'
            .. [1] First paragraph.

               - first
               - second
            RST;
        $result = self::parseRst($input);
        $definition = $result->document()->children()[0];

        self::assertInstanceOf(FootnoteDefinition::class, $definition);
        self::assertCount(2, $definition->body);
        self::assertInstanceOf(Paragraph::class, $definition->body[0]);
        self::assertInstanceOf(BulletList::class, $definition->body[1]);
        self::assertCount(2, $definition->body[1]->children());
        self::assertNoProblems($result);
    }

    public function testEmptyReferenceDefinitionHasAnEmptyBody(): void
    {
        $result = self::parseRst(".. [CIT]\n");
        $definition = $result->document()->children()[0];

        self::assertInstanceOf(CitationDefinition::class, $definition);
        self::assertSame([], $definition->body);
        self::assertNoProblems($result);
    }

    public function testReplaceSubstitutionPreservesNameAndGenericDirective(): void
    {
        $input = ".. |project name| replace:: Alto Rst\n";
        $result = self::parseRst($input);
        $definition = $result->document()->children()[0];

        self::assertInstanceOf(SubstitutionDefinition::class, $definition);
        self::assertSame('project name', $definition->name);
        self::assertSame('replace', $definition->directive->name);
        self::assertSame(['Alto Rst'], $definition->directive->arguments);
        self::assertSame([], $definition->directive->options);
        self::assertSpan(18, 36, $definition->directive);
        self::assertNoProblems($result);
    }

    public function testImageSubstitutionKeepsDirectiveOptionsAndBodySpan(): void
    {
        $input = ".. |logo| image:: logo.png\n   :alt: Project logo\n\n   ignored body\n";
        $result = self::parseRst($input);
        $definition = $result->document()->children()[0];

        self::assertInstanceOf(SubstitutionDefinition::class, $definition);
        self::assertSame('logo', $definition->name);
        self::assertSame('image', $definition->directive->name);
        self::assertSame(['logo.png'], $definition->directive->arguments);
        self::assertSame(['alt' => 'Project logo'], $definition->directive->options);
        self::assertNotNull($definition->directive->rawBody);
        self::assertSame('   ignored body', Source::fromString($input)->slice($definition->directive->rawBody));
        self::assertSame($definition->span()->end(), $definition->directive->span()->end());
        self::assertNoProblems($result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedDefinitions(): iterable
    {
        yield 'empty label' => [".. [] Body.\n"];
        yield 'missing closing bracket' => [".. [1 Body.\n"];
        yield 'label with whitespace' => [".. [not a label] Body.\n"];
        yield 'empty substitution name' => [".. || replace:: Body.\n"];
        yield 'missing substitution closing pipe' => [".. |name replace:: Body.\n"];
        yield 'missing substitution directive' => [".. |name| Body.\n"];
    }

    #[DataProvider('malformedDefinitions')]
    public function testMalformedDefinitionsRecoverAsCommentsWithAProblem(string $input): void
    {
        $result = self::parseRst($input);

        self::assertInstanceOf(Comment::class, $result->document()->children()[0]);
        self::assertSame(['reference/malformed-definition'], self::problemCodes($result));
    }

    public function testMalformedDefinitionConsumesItsIndentedContinuation(): void
    {
        $result = self::parseRst(".. [] malformed\n   continuation\n\nAfter.\n");
        $children = $result->document()->children();

        self::assertCount(2, $children);
        self::assertInstanceOf(Comment::class, $children[0]);
        self::assertSame("[] malformed\ncontinuation", $children[0]->text);
        self::assertSame(['reference/malformed-definition'], self::problemCodes($result));
    }
}
