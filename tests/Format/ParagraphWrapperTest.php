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

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Format\ParagraphWrapper;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\DirectiveBodyKind;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Text;
use Alto\Rst\Node\Transition;
use Alto\Rst\Operation\SourcePatchApplier;
use Alto\Rst\Parser\ParseResult;
use Alto\Rst\Problem\ProblemReport;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Rst;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParagraphWrapper::class)]
final class ParagraphWrapperTest extends TestCase
{
    public function testWrapsAnOverlongTopLevelParagraphAtEightyCharacters(): void
    {
        $source = 'This paragraph contains enough ordinary prose to exceed the default line width while remaining easy to wrap safely.'."\n";
        $patches = self::patches($source);

        self::assertCount(1, $patches);
        self::assertSame(0, $patches[0]->span->start);
        self::assertSame(\strlen(rtrim($source, "\n")), $patches[0]->span->length);
        self::assertSame(
            "This paragraph contains enough ordinary prose to exceed the default line width\nwhile remaining easy to wrap safely.",
            $patches[0]->replacement,
        );
        self::assertSame(
            "This paragraph contains enough ordinary prose to exceed the default line width\nwhile remaining easy to wrap safely.\n",
            new SourcePatchApplier()->apply($source, $patches)->bytes,
        );
    }

    #[DataProvider('lineEndings')]
    public function testPreservesTheSourceLineEnding(string $eol): void
    {
        $source = 'One two three four five six seven eight nine ten.'.$eol;
        $patches = self::patches($source, 20);

        self::assertCount(1, $patches);
        self::assertSame(
            'One two three four'.$eol.'five six seven eight'.$eol.'nine ten.',
            $patches[0]->replacement,
        );
        self::assertSame($patches[0]->replacement.$eol, new SourcePatchApplier()->apply($source, $patches)->bytes);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
        yield 'bare CR' => ["\r"];
    }

    public function testWrapsDirectSectionProseAndKeepsTheDocumentStructure(): void
    {
        $source = "Title\n=====\n\nThis section paragraph contains ordinary prose that needs wrapping at a deliberately short configured width.\n";
        $patches = self::patches($source, 35);
        $formatted = new SourcePatchApplier()->apply($source, $patches)->bytes;

        self::assertCount(1, $patches);
        self::assertSame(
            "Title\n=====\n\nThis section paragraph contains\nordinary prose that needs wrapping\nat a deliberately short configured\nwidth.\n",
            $formatted,
        );
        self::assertFalse(Rst::docutils()->parse($formatted)->problems()->hasProblems());
    }

    public function testStructuralGuardKeepsUnrelatedTypedNodes(): void
    {
        $source = <<<'RST'
            This ordinary top-level paragraph contains enough prose to require wrapping at the deliberately short configured width.

            =====  =====
            First  Second
            =====  =====
            one    two
            =====  =====

            1. numbered item
            RST;
        $patches = self::patches($source, 40);
        $formatted = new SourcePatchApplier()->apply($source, $patches)->bytes;

        self::assertCount(1, $patches);
        self::assertStringContainsString("wrapping at the deliberately short\nconfigured width.", $formatted);
        self::assertFalse(Rst::docutils()->parse($formatted)->problems()->hasProblems());
    }

    public function testWrapsAFileWithoutAFinalLineEnding(): void
    {
        $source = 'One two three four five six seven eight nine ten.';
        $patches = self::patches($source, 20);

        self::assertSame("One two three four\nfive six seven eight\nnine ten.", $patches[0]->replacement);
        self::assertSame($patches[0]->replacement, new SourcePatchApplier()->apply($source, $patches)->bytes);
    }

    public function testDoesNotReflowParagraphsWhoseLinesAlreadyFit(): void
    {
        $source = "A deliberately short line.\nAnother short line.\n";

        self::assertSame([], self::patches($source, 30));
    }

    public function testWrapsParagraphsInBulletAndEnumeratedLists(): void
    {
        $source = <<<'RST'
            - This bullet item contains enough ordinary prose to require wrapping at the deliberately short configured width.

            10. This numbered item contains enough ordinary prose to require wrapping at the deliberately short configured width.
            RST;
        $patches = self::patches($source, 45);
        $formatted = new SourcePatchApplier()->apply($source, $patches)->bytes;

        self::assertCount(2, $patches);
        self::assertSame(
            <<<'RST'
                - This bullet item contains enough ordinary
                  prose to require wrapping at the
                  deliberately short configured width.

                10. This numbered item contains enough
                    ordinary prose to require wrapping at the
                    deliberately short configured width.
                RST,
            $formatted,
        );
        self::assertFalse(Rst::docutils()->parse($formatted)->problems()->hasProblems());
    }

    public function testWrapsMultipleAndNestedListParagraphs(): void
    {
        $source = <<<'RST'
            - First paragraph contains enough ordinary prose to require wrapping at the deliberately short width.

              Second paragraph contains enough ordinary prose to require wrapping at the deliberately short width.

              * Nested paragraph contains enough ordinary prose to require wrapping at the deliberately short width.
            RST;
        $patches = self::patches($source, 42);
        $formatted = new SourcePatchApplier()->apply($source, $patches)->bytes;

        self::assertCount(3, $patches);
        self::assertStringContainsString("- First paragraph contains enough ordinary\n  prose", $formatted);
        self::assertStringContainsString("  Second paragraph contains enough\n  ordinary prose", $formatted);
        self::assertStringContainsString("  * Nested paragraph contains enough\n    ordinary prose", $formatted);
        self::assertFalse(Rst::docutils()->parse($formatted)->problems()->hasProblems());
    }

    #[DataProvider('lineEndings')]
    public function testWrapsASimpleAdmonitionBody(string $eol): void
    {
        $source = '.. note::'.$eol.$eol
            .'    This admonition contains enough ordinary prose to require wrapping at the deliberately short configured width.'.$eol;
        $patches = self::patches($source, 44);
        $formatted = new SourcePatchApplier()->apply($source, $patches)->bytes;

        self::assertCount(1, $patches);
        self::assertSame(
            '.. note::'.$eol.$eol
            .'    This admonition contains enough ordinary'.$eol
            .'    prose to require wrapping at the'.$eol
            .'    deliberately short configured width.'.$eol,
            $formatted,
        );
        self::assertFalse(Rst::docutils()->parse($formatted)->problems()->hasProblems());
    }

    public function testWrapsAGenericAndSphinxAdmonition(): void
    {
        $generic = <<<'RST'
            .. admonition:: Custom title

                This generic admonition contains enough ordinary prose to require wrapping at a deliberately short width.
            RST;
        $seealso = <<<'RST'
            .. seealso::

                This Sphinx admonition contains enough ordinary prose to require wrapping at a deliberately short width.
            RST;

        self::assertCount(1, self::patches($generic, 42));

        $source = Source::fromString($seealso);
        $result = Rst::sphinx()->parse($seealso);
        self::assertCount(1, new ParagraphWrapper(42)->patches($result, $source, Profile::sphinx()));
    }

    public function testLeavesAProfileUnknownAdmonitionUntouched(): void
    {
        $source = <<<'RST'
            .. seealso::

                This Sphinx-only admonition contains enough ordinary prose to require wrapping at a deliberately short width.
            RST;

        self::assertSame([], self::patches($source, 42));
    }

    public function testIgnoresComplexAdmonitionsAndOtherStructuredContexts(): void
    {
        $source = <<<'RST'
            .. note::

                First paragraph has a deliberately long line that would otherwise need wrapping by this formatter pass.

                Second paragraph keeps this admonition outside the simple-body contract.

            .. code-block:: php

                $this->literalCodeLineRemainsUntouchedBecauseItIsNotProseAndItIsDeliberatelyLong();

            A top-level paragraph remains short.

                This block quote has a deliberately long line that would otherwise need wrapping by this formatter pass.

            ::

                This literal block has a deliberately long line that would otherwise need wrapping by this formatter pass.

            +----------------------+----------------------+
            | This table cell has  | content that remains |
            | deliberately long.   | untouched.           |
            +----------------------+----------------------+
            RST;

        self::assertSame([], self::patches($source, 40));
    }

    public function testIgnoresInlineMarkupAndEscapedWhitespace(): void
    {
        $markup = 'This paragraph contains **strong markup** and enough ordinary prose to exceed a deliberately short width safely.'."\n";
        $escaped = 'This paragraph contains escaped\\ whitespace and enough ordinary prose to exceed a deliberately short width safely.'."\n";

        self::assertSame([], self::patches($markup, 40));
        self::assertSame([], self::patches($escaped, 40));
    }

    public function testIgnoresAParagraphWithAnUnbreakableLongUrl(): void
    {
        $source = 'Read https://example.com/a/very/long/path/that/must/not/be/split for the complete explanation.'."\n";

        self::assertSame([], self::patches($source, 40));
    }

    public function testCanWrapProseAroundAShortStandaloneUrl(): void
    {
        $source = 'Read https://example.com and continue with enough ordinary prose to exceed the deliberately short line width.'."\n";
        $patches = self::patches($source, 40);

        self::assertCount(1, $patches);
        self::assertSame(
            "Read https://example.com and continue\nwith enough ordinary prose to exceed the\ndeliberately short line width.",
            $patches[0]->replacement,
        );
    }

    public function testIgnoresMixedLineEndingsWithinAParagraph(): void
    {
        $source = "This first line is deliberately much too long for the configured width.\r\nSecond line is also prose.\n";

        self::assertSame([], self::patches($source, 40));
    }

    public function testIgnoresReferenceRecovery(): void
    {
        $source = 'This paragraph has an unresolved reference_ and enough ordinary prose to exceed the deliberately short width.'."\n";

        self::assertSame([], self::patches($source, 40));
    }

    public function testCanWrapUnrelatedProseWithoutChangingAReferenceProblem(): void
    {
        $source = "An unresolved reference_.\n\nThis independent paragraph contains enough ordinary prose to exceed the deliberately short configured width.\n";
        $patches = self::patches($source, 40);
        $formatted = new SourcePatchApplier()->apply($source, $patches)->bytes;

        self::assertCount(1, $patches);
        self::assertStringContainsString("This independent paragraph contains\nenough ordinary prose", $formatted);
        self::assertSame(
            ['reference/unresolved-target'],
            array_map(
                static fn ($problem): string => $problem->code,
                Rst::docutils()->parse($formatted)->references()->problems()->problems(),
            ),
        );
    }

    public function testIgnoresParserRecovery(): void
    {
        $source = ':author: This unsupported field list has a deliberately long value that must remain untouched.'."\n";

        self::assertSame([], self::patches($source, 40));
    }

    public function testCountsUtf8CharactersInsteadOfBytes(): void
    {
        $source = str_repeat("\u{00E9}", 20).' '.str_repeat("\u{00E0}", 20)."\n";
        $patches = self::patches($source, 30);

        self::assertCount(1, $patches);
        self::assertSame(str_repeat("\u{00E9}", 20)."\n".str_repeat("\u{00E0}", 20), $patches[0]->replacement);
    }

    public function testWrappingIsIdempotent(): void
    {
        $source = 'One two three four five six seven eight nine ten eleven twelve thirteen fourteen.'."\n";
        $first = new SourcePatchApplier()->apply($source, self::patches($source, 25))->bytes;

        self::assertSame([], self::patches($first, 25));
    }

    public function testRejectsAnInvalidWidth(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ParagraphWrapper(0);
    }

    public function testRejectsAParseResultFromAnotherSource(): void
    {
        $source = Source::fromString('One source.');
        $result = Rst::docutils()->parse('Another source.');

        $this->expectException(InvalidArgumentException::class);

        new ParagraphWrapper()->patches($result, $source);
    }

    public function testStructuralGuardRejectsAManuallyInconsistentDocument(): void
    {
        $bytes = 'This manually assembled paragraph contains enough ordinary prose to require wrapping at a short width.';
        $source = Source::fromString($bytes);
        $parsed = Rst::docutils()->parse($bytes);
        $paragraph = $parsed->document()->children()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        $span = ByteSpan::of(0, \strlen($bytes));
        $document = new Document($span, [$paragraph, new Transition(ByteSpan::of(0, 1))]);
        $result = new ParseResult($document, new ProblemReport(), $source, Profile::docutils());

        self::assertSame([], new ParagraphWrapper(30)->patches($result, $source, Profile::docutils()));
    }

    public function testManualParagraphWithATabPrefixIsNotWrapped(): void
    {
        $text = 'This paragraph contains enough ordinary prose to require wrapping at a deliberately short width.';
        $bytes = "\t".$text;
        $source = Source::fromString($bytes);
        $paragraphSpan = ByteSpan::of(0, \strlen($bytes));
        $paragraph = new Paragraph(
            $paragraphSpan,
            new Text(ByteSpan::of(1, \strlen($text)), $text),
        );
        $result = new ParseResult(
            new Document($paragraphSpan, [$paragraph]),
            new ProblemReport(),
            $source,
            Profile::docutils(),
        );

        self::assertSame([], new ParagraphWrapper(30)->patches($result, $source, Profile::docutils()));
    }

    public function testManualParagraphOutsideItsPhysicalLinesIsNotWrapped(): void
    {
        $bytes = 'This manually assembled paragraph contains enough ordinary prose to require wrapping.';
        $source = Source::fromString($bytes);
        $paragraph = new Paragraph(
            ByteSpan::of(0, \strlen($bytes) + 5),
            new Text(ByteSpan::of(0, \strlen($bytes)), $bytes),
        );
        $document = new Document(ByteSpan::of(0, \strlen($bytes) + 5), [$paragraph]);
        $result = new ParseResult($document, new ProblemReport(), $source, Profile::docutils());

        self::assertSame([], new ParagraphWrapper(30)->patches($result, $source, Profile::docutils()));
    }

    public function testManualAdmonitionBodiesKeepConservativeWidthAndShapeGuards(): void
    {
        foreach (
            [
                [4, '    This body is wider than the available content width.'],
                [30, '    - This list body is not a simple paragraph.'],
                [80, '    Short body.'],
            ] as [$width, $body]
        ) {
            $source = Source::fromString($body);
            $span = ByteSpan::of(0, \strlen($body));
            $directive = new Directive(
                $span,
                'note',
                rawBody: $span,
                bodyKind: DirectiveBodyKind::Opaque,
            );
            $result = new ParseResult(
                new Document($span, [$directive]),
                new ProblemReport(),
                $source,
                Profile::docutils(),
            );

            self::assertSame([], new ParagraphWrapper($width)->patches($result, $source, Profile::docutils()));
        }
    }

    /**
     * @return list<\Alto\Rst\Operation\SourcePatch>
     */
    private static function patches(string $bytes, int $width = 80): array
    {
        $source = Source::fromString($bytes);
        $result = Rst::docutils()->parse($bytes);

        return new ParagraphWrapper($width)->patches($result, $source, Profile::docutils());
    }
}
