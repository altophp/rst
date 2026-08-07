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

namespace Alto\Rst\Tests\Parser\Inline;

use Alto\Rst\Node\Inline\CitationReference;
use Alto\Rst\Node\Inline\FootnoteReference;
use Alto\Rst\Node\Inline\InlineTarget;
use Alto\Rst\Node\Inline\SubstitutionReference;
use Alto\Rst\Parser\InlineParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(InlineParser::class)]
final class InlineParserReferenceTest extends InlineParserTestCase
{
    #[DataProvider('substitutionCases')]
    public function testSubstitutionReferences(string $text, string $expected): void
    {
        self::assertSame($expected, self::outline($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function substitutionCases(): iterable
    {
        yield 'substitution' => ['The |name| engine.', 'text(The )substitution(name)text( engine.)'];
        yield 'substitution reference' => ['The |name|_ engine.', 'text(The )substitution(name,ref)text( engine.)'];
        yield 'anonymous substitution reference' => ['The |name|__ engine.', 'text(The )substitution(name,ref,anonymous)text( engine.)'];
        yield 'multi word name' => ['The |a b| engine.', 'text(The )substitution(a b)text( engine.)'];
        yield 'name across lines' => ["The |a\n  b| engine.", 'text(The )substitution(a b)text( engine.)'];
        yield 'double pipe' => ['A || pipe.', 'text(A || pipe.)'];
        yield 'pipe before whitespace' => ['A | pipe.', 'text(A | pipe.)'];
    }

    public function testSubstitutionSpanCoversTheSuffix(): void
    {
        $node = self::nodeAt(self::parseInline('The |name|_ engine.'), 1, SubstitutionReference::class);

        self::assertSame([4, 11], self::bounds($node));
        self::assertTrue($node->reference);
    }

    public function testAnonymousSubstitutionPreservesTheDoubleSuffix(): void
    {
        $node = self::nodeAt(self::parseInline('The |name|__ engine.'), 1, SubstitutionReference::class);

        self::assertSame([4, 12], self::bounds($node));
        self::assertTrue($node->reference);
        self::assertTrue($node->anonymous);
    }

    public function testUnclosedSubstitutionDegradesToText(): void
    {
        self::assertSame('text(A |unclosed here.)', self::outline('A |unclosed here.'));
        self::assertSame(['inline/unmatched-start-string'], self::problemCodes('A |unclosed here.'));
    }

    #[DataProvider('labelCases')]
    public function testFootnoteAndCitationReferences(string $text, string $expected): void
    {
        self::assertSame($expected, self::outline($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function labelCases(): iterable
    {
        yield 'numbered footnote' => ['A note [1]_ here.', 'text(A note )footnote(1)text( here.)'];
        yield 'auto-numbered footnote' => ['A note [#]_ here.', 'text(A note )footnote(#)text( here.)'];
        yield 'labelled auto footnote' => ['A note [#label]_ here.', 'text(A note )footnote(#label)text( here.)'];
        yield 'auto-symbol footnote' => ['A note [*]_ here.', 'text(A note )footnote(*)text( here.)'];
        yield 'citation' => ['As in [CIT2002]_ shown.', 'text(As in )citation(CIT2002)text( shown.)'];
        yield 'mixed label' => ['As in [1a]_ shown.', 'text(As in )citation(1a)text( shown.)'];
        yield 'bracketed prose' => ['A [not a label]_ here.', 'text(A [not a label]_ here.)'];
        yield 'bracket without a suffix' => ['A [1] here.', 'text(A [1] here.)'];
        yield 'double suffix' => ['A [1]__ here.', 'text(A [1]__ here.)'];
    }

    public function testFootnoteSpan(): void
    {
        $node = self::nodeAt(self::parseInline('A note [#label]_ here.'), 1, FootnoteReference::class);

        self::assertSame([7, 16], self::bounds($node));
        self::assertSame('#label', $node->label);
    }

    public function testCitationSpan(): void
    {
        $node = self::nodeAt(self::parseInline('As in [CIT2002]_ shown.'), 1, CitationReference::class);

        self::assertSame([6, 16], self::bounds($node));
        self::assertSame('CIT2002', $node->label);
    }

    public function testEmptyLabelIsReported(): void
    {
        self::assertSame('text(Empty []_ label.)', self::outline('Empty []_ label.'));
        self::assertSame(['inline/empty-reference'], self::problemCodes('Empty []_ label.'));
    }

    #[DataProvider('inlineTargetCases')]
    public function testInlineTargets(string $text, string $expected): void
    {
        self::assertSame($expected, self::outline($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function inlineTargetCases(): iterable
    {
        yield 'target' => ['An _`inline target` inside.', 'text(An )target(inline target)text( inside.)'];
        yield 'target at the start' => ['_`target` first.', 'target(target)text( first.)'];
        yield 'target across lines' => ["An _`inline\n  target` inside.", 'text(An )target(inline target)text( inside.)'];
        yield 'underscore alone' => ['An _ underscore.', 'text(An _ underscore.)'];
        yield 'underscore before whitespace' => ['An _` target.', 'text(An _` target.)'];
    }

    public function testInlineTargetSpan(): void
    {
        $node = self::nodeAt(self::parseInline('An _`inline target` inside.'), 1, InlineTarget::class);

        self::assertSame([3, 19], self::bounds($node));
        self::assertSame('inline target', $node->name);
    }

    public function testUnclosedInlineTargetDegradesToText(): void
    {
        self::assertSame('text(An _`unclosed here.)', self::outline('An _`unclosed here.'));
        self::assertSame(['inline/unmatched-start-string'], self::problemCodes('An _`unclosed here.'));
    }

    public function testReferencesReportNothing(): void
    {
        self::assertNoProblems('A |name| note [1]_ and [CIT2002]_ with an _`inline target`.');
    }
}
