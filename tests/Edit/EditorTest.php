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

namespace Alto\Rst\Tests\Edit;

use Alto\Rst\Edit\Editor;
use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Exception\PatchConflictException;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\HyperlinkTarget;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Section;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Parser\ParseResult;
use Alto\Rst\Problem\ProblemReport;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Reference\ReferenceStatus;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Editor::class)]
final class EditorTest extends TestCase
{
    public function testReplacesACompleteSectionBodyWithoutCapturingItsSibling(): void
    {
        $rst = "Outer\n=====\n\nIntro.\n\nInner\n-----\n\nNested.\n\nPeer\n====\n\nPeer body.\n";
        [$source, $result] = self::parse($rst);
        $outer = self::section($result);

        $editor = new Editor($source, $result);
        $editor->replaceSectionBody($outer, "Fresh.\n");

        $edited = $editor->toRst();

        self::assertSame(
            "Outer\n=====\n\nFresh.\n\nPeer\n====\n\nPeer body.\n",
            $edited,
        );

        [, $reparsed] = self::parse($edited);
        $sections = $reparsed->document()->children();
        self::assertCount(2, $sections);
        self::assertInstanceOf(Section::class, $sections[0]);
        self::assertCount(1, $sections[0]->body());
        self::assertInstanceOf(Paragraph::class, $sections[0]->body()[0]);
        self::assertInstanceOf(Section::class, $sections[1]);
        self::assertSame('Peer', $sections[1]->title->text->text);
    }

    public function testAddsABodyToAnEmptySection(): void
    {
        $rst = "Empty\n=====\n\nNext\n====\n\nNext body.\n";
        [$source, $result] = self::parse($rst);

        $edited = new Editor($source, $result)
            ->replaceSectionBody(self::section($result), "Created.\n")
            ->toRst();

        self::assertSame(
            "Empty\n=====\n\nCreated.\n\nNext\n====\n\nNext body.\n",
            $edited,
        );

        [, $reparsed] = self::parse($edited);
        self::assertCount(1, self::section($reparsed)->body());
    }

    public function testEmptyReplacementRemovesNestedSubsections(): void
    {
        $rst = "Outer\n=====\n\nIntro.\n\nInner\n-----\n\nNested.\n\nPeer\n====\n";
        [$source, $result] = self::parse($rst);

        $edited = new Editor($source, $result)
            ->replaceSectionBody(self::section($result), '')
            ->toRst();

        self::assertSame("Outer\n=====\n\nPeer\n====\n", $edited);

        [, $reparsed] = self::parse($edited);
        $sections = $reparsed->document()->children();
        self::assertCount(2, $sections);
        self::assertInstanceOf(Section::class, $sections[0]);
        self::assertSame([], $sections[0]->body());
    }

    public function testPreservesBomCrLfAndEveryByteOutsideTheBodyPatch(): void
    {
        $rst = "\xEF\xBB\xBFOne\r\n===\r\n\r\nBody.\r\n\r\nTwo\r\n===\r\n";
        [$source, $result] = self::parse($rst);
        $editor = new Editor($source, $result);

        $editor->replaceSectionBody(self::section($result), "Nouveau\nsur deux lignes\n");

        $patch = $editor->patches()[0];
        $edited = $editor->toRst();

        self::assertSame(
            "\xEF\xBB\xBFOne\r\n===\r\n\r\nNouveau\r\nsur deux lignes\r\n\r\nTwo\r\n===\r\n",
            $edited,
        );
        self::assertSame(
            substr($rst, 0, $patch->span->start),
            substr($edited, 0, $patch->span->start),
        );
        $unchangedSuffix = substr($rst, $patch->span->end());
        self::assertNotSame('', $unchangedSuffix);
        self::assertSame($unchangedSuffix, substr($edited, -\strlen($unchangedSuffix)));

        [$reparsedSource, $reparsed] = self::parse($edited);
        self::assertTrue($reparsedSource->hasBom());
        self::assertCount(2, $reparsed->document()->children());
    }

    public function testInsertsTopLevelContentWithTheSourceLineEnding(): void
    {
        $rst = "One\r\n===\r\n\r\nBody.\r\n";
        [$source, $result] = self::parse($rst);

        $edited = new Editor($source, $result)
            ->insertTopLevel("Two\n===\n")
            ->toRst();

        self::assertSame(
            "One\r\n===\r\n\r\nBody.\r\n\r\nTwo\r\n===\r\n",
            $edited,
        );

        [, $reparsed] = self::parse($edited);
        self::assertCount(2, $reparsed->document()->children());
    }

    public function testTopLevelInsertionPreservesABomOnlySource(): void
    {
        $rst = "\xEF\xBB\xBF";
        [$source, $result] = self::parse($rst);

        $edited = new Editor($source, $result)
            ->insertTopLevel("Title\n=====\n")
            ->toRst();

        self::assertSame("\xEF\xBB\xBFTitle\n=====", $edited);

        [$reparsedSource, $reparsed] = self::parse($edited);
        self::assertTrue($reparsedSource->hasBom());
        self::assertCount(1, $reparsed->document()->children());
    }

    public function testPlansAreSortedAndRepeatedCallsAreIdempotent(): void
    {
        $rst = "One\n===\n\nOld.\n";
        [$source, $result] = self::parse($rst);
        $section = self::section($result);
        $editor = new Editor($source, $result);

        $editor->insertTopLevel("Two\n===\n");
        $editor->replaceSectionBody($section, 'New.');
        $editor->replaceSectionBody($section, 'New.');

        self::assertCount(2, $editor->patches());
        self::assertLessThan(
            $editor->patches()[1]->span->start,
            $editor->patches()[0]->span->start,
        );
        self::assertSame($editor->toRst(), $editor->toRst());

        [, $reparsed] = self::parse($editor->toRst());
        self::assertCount(2, $reparsed->document()->children());
    }

    public function testReplacingABodyWithItsExistingBytesDoesNotCreateAPatch(): void
    {
        $rst = "One\n===\n\nOld.\n";
        [$source, $result] = self::parse($rst);
        $editor = new Editor($source, $result);

        $editor->replaceSectionBody(self::section($result), "Old.\n");

        self::assertSame([], $editor->patches());
        self::assertSame($rst, $editor->toRst());
    }

    public function testBareCarriageReturnInputKeepsItsLineEnding(): void
    {
        $rst = "One\r===\r\rOld.\r";
        [$source, $result] = self::parse($rst);

        $edited = new Editor($source, $result)
            ->replaceSectionBody(self::section($result), "First\nSecond\n")
            ->toRst();

        self::assertSame("One\r===\r\rFirst\rSecond\r", $edited);

        [, $reparsed] = self::parse($edited);
        $body = self::section($reparsed)->body();
        self::assertCount(1, $body);
        self::assertInstanceOf(Paragraph::class, $body[0]);
        self::assertSame("First\rSecond", $body[0]->text->text);
    }

    public function testReplacesTheBodyOfAnOverlineSection(): void
    {
        $rst = "=====\nTitle\n=====\n\nOld.\n";
        [$source, $result] = self::parse($rst);

        $edited = new Editor($source, $result)
            ->replaceSectionBody(self::section($result), 'New.')
            ->toRst();

        self::assertSame("=====\nTitle\n=====\n\nNew.\n", $edited);

        [, $reparsed] = self::parse($edited);
        $section = self::section($reparsed);
        $body = $section->body();
        self::assertTrue($section->hasOverline);
        self::assertCount(1, $body);
        self::assertInstanceOf(Paragraph::class, $body[0]);
        self::assertSame('New.', $body[0]->text->text);
    }

    public function testRejectsOverlappingParentAndSubsectionEditsTransactionally(): void
    {
        $rst = "Outer\n=====\n\nIntro.\n\nInner\n-----\n\nNested.\n";
        [$source, $result] = self::parse($rst);
        $outer = self::section($result);
        $inner = $outer->body()[1];
        self::assertInstanceOf(Section::class, $inner);
        $editor = new Editor($source, $result);
        $editor->replaceSectionBody($outer, 'Outer replacement.');

        try {
            $editor->replaceSectionBody($inner, 'Nested replacement.');
            self::fail('Expected overlapping section edits to conflict.');
        } catch (PatchConflictException) {
            self::assertCount(1, $editor->patches());
            self::assertStringContainsString('Outer replacement.', $editor->toRst());
        }
    }

    public function testRejectsASectionHandleFromAnotherParseResult(): void
    {
        $rst = "Title\n=====\n\nBody.\n";
        [$source, $result] = self::parse($rst);
        [, $otherResult] = self::parse($rst);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Section handle does not belong to this editor parse result.');

        new Editor($source, $result)->replaceSectionBody(self::section($otherResult), 'Other.');
    }

    public function testRejectsAParseResultFromDifferentBytesOfTheSameLength(): void
    {
        [, $result] = self::parse("Title\n=====\n\nBody.\n");
        $otherSource = Source::fromString("Other\n=====\n\nText.\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Parse result was not produced from the editor source.');

        new Editor($otherSource, $result);
    }

    public function testUpdatesAFlagDirectiveOptionWithAMinimalPatch(): void
    {
        $rst = ".. code-block:: php\n   :linenos:\n   :caption: Before\n\n   echo 'ok';\n";
        [$source, $result] = self::parse($rst, Profile::sphinx());
        $editor = new Editor($source, $result);

        $editor->setDirectiveOption(self::node($result, Directive::class), 'caption', 'Après');

        self::assertSame(
            ".. code-block:: php\n   :linenos:\n   :caption: Après\n\n   echo 'ok';\n",
            $editor->toRst(),
        );
        self::assertCount(1, $editor->patches());
        self::assertSame('   :caption: Before', $source->slice($editor->patches()[0]->span));

        [, $reparsed] = self::parse($editor->toRst(), Profile::sphinx());
        $directive = self::node($reparsed, Directive::class);
        self::assertSame('', $directive->options['linenos']);
        self::assertSame('Après', $directive->options['caption']);
    }

    public function testUpdatesAMultilineDirectiveOptionAndPreservesCrLf(): void
    {
        $rst = ".. image:: diagram.svg\r\n   :alt: First\r\n      second\r\n\r\n";
        [$source, $result] = self::parse($rst);
        $editor = new Editor($source, $result);

        $editor->setDirectiveOption(self::node($result, Directive::class), 'alt', "Un\nDeux");

        self::assertSame(
            ".. image:: diagram.svg\r\n   :alt: Un\r\n      Deux\r\n\r\n",
            $editor->toRst(),
        );
        [, $reparsed] = self::parse($editor->toRst());
        self::assertSame("Un\nDeux", self::node($reparsed, Directive::class)->options['alt']);
    }

    public function testAddsAndRemovesDirectiveOptionsWithoutTouchingTheBody(): void
    {
        $rst = ".. image:: diagram.svg\n\n   Caption.\n";
        [$source, $result] = self::parse($rst);
        $directive = self::node($result, Directive::class);

        $withOption = new Editor($source, $result)
            ->setDirectiveOption($directive, 'alt', 'Diagram')
            ->toRst();

        self::assertSame(".. image:: diagram.svg\n   :alt: Diagram\n\n   Caption.\n", $withOption);

        [$updatedSource, $updated] = self::parse($withOption);
        $withoutOption = new Editor($updatedSource, $updated)
            ->removeDirectiveOption(self::node($updated, Directive::class), 'alt')
            ->toRst();

        self::assertSame($rst, $withoutOption);
    }

    public function testAddsAFlagOptionAtEndOfFileWithoutAddingATrailingNewline(): void
    {
        $rst = '.. code-block:: php';
        [$source, $result] = self::parse($rst, Profile::sphinx());

        $edited = new Editor($source, $result)
            ->setDirectiveOption(self::node($result, Directive::class), 'linenos')
            ->toRst();

        self::assertSame(".. code-block:: php\n   :linenos:", $edited);
    }

    public function testRejectsDuplicateDirectiveOptionsTransactionally(): void
    {
        $rst = ".. image:: diagram.svg\n   :alt: One\n   :ALT: Two\n";
        [$source, $result] = self::parse($rst);
        $editor = new Editor($source, $result);

        try {
            $editor->setDirectiveOption(self::node($result, Directive::class), 'alt', 'Three');
            self::fail('Expected duplicate options to be rejected.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('duplicated', $error->getMessage());
            self::assertSame([], $editor->patches());
            self::assertSame($rst, $editor->toRst());
        }
    }

    public function testRemovingAMissingOptionIsANoOp(): void
    {
        $rst = ".. image:: diagram.svg\n   :alt: Diagram\n";
        [$source, $result] = self::parse($rst);
        $editor = new Editor($source, $result);

        $editor->removeDirectiveOption(self::node($result, Directive::class), 'width');

        self::assertSame([], $editor->patches());
        self::assertSame($rst, $editor->toRst());
    }

    public function testRemovingADuplicateOptionIsRejectedTransactionally(): void
    {
        $rst = ".. image:: diagram.svg\n   :alt: One\n   :ALT: Two\n";
        [$source, $result] = self::parse($rst);
        $editor = new Editor($source, $result);

        try {
            $editor->removeDirectiveOption(self::node($result, Directive::class), 'alt');
            self::fail('Expected duplicate options to be rejected.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('duplicated', $error->getMessage());
            self::assertSame([], $editor->patches());
        }
    }

    public function testAddsAnOptionAfterExistingOptions(): void
    {
        $rst = ".. image:: diagram.svg\n   :alt: Diagram\n\n   Caption.\n";
        [$source, $result] = self::parse($rst);

        $edited = new Editor($source, $result)
            ->setDirectiveOption(self::node($result, Directive::class), 'width', '640')
            ->toRst();

        self::assertSame(
            ".. image:: diagram.svg\n   :alt: Diagram\n   :width: 640\n\n   Caption.\n",
            $edited,
        );
    }

    public function testRejectsInvalidDirectiveOptionNames(): void
    {
        $rst = ".. image:: diagram.svg\n";
        [$source, $result] = self::parse($rst);

        foreach (['', ' alt', '1alt', 'alt value'] as $name) {
            try {
                new Editor($source, $result)->setDirectiveOption(
                    self::node($result, Directive::class),
                    $name,
                    'value',
                );
                self::fail(\sprintf('Expected option name "%s" to be rejected.', $name));
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('Invalid directive option name', $error->getMessage());
            }
        }
    }

    public function testRejectsADirectiveHandleFromAnotherParseResult(): void
    {
        $rst = ".. image:: diagram.svg\n";
        [$source, $result] = self::parse($rst);
        [, $otherResult] = self::parse($rst);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Directive handle does not belong to this editor parse result.');

        new Editor($source, $result)->setDirectiveOption(
            self::node($otherResult, Directive::class),
            'alt',
            'Other',
        );
    }

    public function testRenamesATargetAndEverySupportedIncomingReference(): void
    {
        $rst = "old_. `old`_. `Label <old_>`_. :ref:`old`. :ref:`Title <old>`.\n\n.. _old:\n";
        [$source, $result] = self::parse($rst, Profile::sphinx());
        $editor = new Editor($source, $result);

        $editor->renameTarget(self::node($result, HyperlinkTarget::class), 'new-name');

        $edited = $editor->toRst();
        self::assertSame(
            "new-name_. `new-name`_. `Label <new-name_>`_. :ref:`new-name`. :ref:`Title <new-name>`.\n\n.. _new-name:\n",
            $edited,
        );
        [, $reparsed] = self::parse($edited, Profile::sphinx());

        foreach ($reparsed->references()->references() as $reference) {
            self::assertSame(ReferenceStatus::Resolved, $reference->status);
            self::assertSame('new-name', $reference->label);
        }
    }

    public function testRenamesATargetToAPhraseWhenReferenceFormsAllowIt(): void
    {
        $rst = "old_. `old`_. :ref:`old`.\n\n.. _old:\n";
        [$source, $result] = self::parse($rst, Profile::sphinx());

        $edited = new Editor($source, $result)
            ->renameTarget(self::node($result, HyperlinkTarget::class), 'nouveau nom')
            ->toRst();

        self::assertSame(
            "`nouveau nom`_. `nouveau nom`_. :ref:`nouveau nom`.\n\n.. _`nouveau nom`:\n",
            $edited,
        );
    }

    public function testPhraseRenameRejectsEmbeddedAliasesTransactionally(): void
    {
        $rst = "`Label <old_>`_.\n\n.. _old:\n";
        [$source, $result] = self::parse($rst);
        $editor = new Editor($source, $result);

        try {
            $editor->renameTarget(self::node($result, HyperlinkTarget::class), 'new name');
            self::fail('Expected an unsafe embedded alias rewrite to be rejected.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('Embedded hyperlink aliases', $error->getMessage());
            self::assertSame([], $editor->patches());
            self::assertSame($rst, $editor->toRst());
        }
    }

    public function testRenamesAUtf8TargetUsingOriginalByteOffsets(): void
    {
        $rst = "Préface. café_.\r\n\r\n.. _café: https://example.com/\r\n";
        [$source, $result] = self::parse($rst);

        $edited = new Editor($source, $result)
            ->renameTarget(self::node($result, HyperlinkTarget::class), 'thé')
            ->toRst();

        self::assertSame(
            "Préface. thé_.\r\n\r\n.. _thé: https://example.com/\r\n",
            $edited,
        );
    }

    public function testRenamesATargetOnTheBomLine(): void
    {
        $rst = "\xEF\xBB\xBF.. _old:\r\n\r\nold_.\r\n";
        [$source, $result] = self::parse($rst);

        $edited = new Editor($source, $result)
            ->renameTarget(self::node($result, HyperlinkTarget::class), 'new')
            ->toRst();

        self::assertSame("\xEF\xBB\xBF.. _new:\r\n\r\nnew_.\r\n", $edited);
    }

    public function testRenamesIndirectTargetDestinationsWithoutChangingAliasReferences(): void
    {
        $rst = "alias_. old_.\n\n.. _alias: old_\n.. _old:\n";
        [$source, $result] = self::parse($rst);

        $edited = new Editor($source, $result)
            ->renameTarget(self::targetNamed($result, 'old'), 'new name')
            ->toRst();

        self::assertSame(
            "alias_. `new name`_.\n\n.. _alias: `new name`_\n.. _`new name`:\n",
            $edited,
        );

        [, $reparsed] = self::parse($edited);

        foreach ($reparsed->references()->references() as $reference) {
            self::assertSame(ReferenceStatus::Resolved, $reference->status);
            self::assertSame('new name', $reference->target?->name);
        }
    }

    public function testTargetRenameRejectsNameCollisionsTransactionally(): void
    {
        $rst = ".. _first:\n\n.. _second:\n";
        [$source, $result] = self::parse($rst);
        $editor = new Editor($source, $result);

        try {
            $editor->renameTarget(self::node($result, HyperlinkTarget::class), 'second');
            self::fail('Expected a target name collision.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('already exists', $error->getMessage());
            self::assertSame([], $editor->patches());
            self::assertSame($rst, $editor->toRst());
        }
    }

    public function testTargetRenameRejectsAnAmbiguousExistingName(): void
    {
        $rst = ".. _same:\n\n.. _same:\n";
        [$source, $result] = self::parse($rst);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ambiguous');

        new Editor($source, $result)->renameTarget(
            self::node($result, HyperlinkTarget::class),
            'new',
        );
    }

    public function testTargetRenameRejectsIncompleteReferenceCoverage(): void
    {
        $rst = ".. _old:\n\n.. custom:: text\n\n   old_\n";
        [$source, $result] = self::parse($rst);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('complete document reference coverage');

        new Editor($source, $result)->renameTarget(
            self::node($result, HyperlinkTarget::class),
            'new',
        );
    }

    public function testTargetRenameRejectsAnonymousTargets(): void
    {
        $rst = ".. __: https://example.com/\n";
        [$source, $result] = self::parse($rst);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Anonymous targets cannot be renamed.');

        new Editor($source, $result)->renameTarget(
            self::node($result, HyperlinkTarget::class),
            'new',
        );
    }

    public function testTargetRenameRejectsAHandleFromAnotherParseResult(): void
    {
        $rst = ".. _old:\n";
        [$source, $result] = self::parse($rst);
        [, $otherResult] = self::parse($rst);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Target handle does not belong to this editor parse result.');

        new Editor($source, $result)->renameTarget(
            self::node($otherResult, HyperlinkTarget::class),
            'new',
        );
    }

    public function testTargetRenameRejectsInvalidNames(): void
    {
        $rst = ".. _old:\n";
        [$source, $result] = self::parse($rst);

        foreach (['', ' new', "new\nname", 'new`name'] as $name) {
            try {
                new Editor($source, $result)->renameTarget(
                    self::node($result, HyperlinkTarget::class),
                    $name,
                );
                self::fail(\sprintf('Expected target name "%s" to be rejected.', $name));
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('Invalid target name', $error->getMessage());
            }
        }
    }

    public function testRenamesBacktickAndIndirectTargetsAlongsideExternalTargets(): void
    {
        $rst = "`old phrase`_. `alias name`_.\n\n"
            . ".. _`alias name`: `old phrase`_\n"
            . ".. _external: https://example.com/\n"
            . ".. _`old phrase`:\n";
        [$source, $result] = self::parse($rst);

        $edited = new Editor($source, $result)
            ->renameTarget(self::targetNamed($result, 'old phrase'), 'new')
            ->toRst();

        self::assertSame(
            "`new`_. `alias name`_.\n\n"
            . ".. _`alias name`: new_\n"
            . ".. _external: https://example.com/\n"
            . ".. _new:\n",
            $edited,
        );
    }

    public function testRenamesATargetWhoseDeclarationContainsAnEscapedColon(): void
    {
        $rst = "`old: name`_.\n\n.. _old\\: name:\n";
        [$source, $result] = self::parse($rst);

        $edited = new Editor($source, $result)
            ->renameTarget(self::node($result, HyperlinkTarget::class), 'new')
            ->toRst();

        self::assertSame("`new`_.\n\n.. _new:\n", $edited);
    }

    public function testTargetRenameRejectsDuplicateDefinitionsForTheSameHandle(): void
    {
        $rst = ".. _old:\n";
        $source = Source::fromString($rst);
        $span = ByteSpan::of(0, \strlen($rst));
        $target = new HyperlinkTarget($span, 'old', '');
        $document = new Document($span, [$target, $target]);
        $result = new ParseResult($document, new ProblemReport(), $source, Profile::docutils());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no unique hyperlink definition');

        new Editor($source, $result)->renameTarget($target, 'new');
    }

    public function testTargetRenameRejectsADeclarationThatDoesNotMatchItsRomNode(): void
    {
        $rst = "not a target\n";
        $source = Source::fromString($rst);
        $span = ByteSpan::of(0, \strlen($rst));
        $target = new HyperlinkTarget($span, 'old', '');
        $document = new Document($span, [$target]);
        $result = new ParseResult($document, new ProblemReport(), $source, Profile::docutils());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be rewritten safely');

        new Editor($source, $result)->renameTarget($target, 'new');
    }

    public function testTopLevelInsertionCoversEmptyBlankAndUnterminatedInputs(): void
    {
        [$emptySource, $emptyResult] = self::parse('');
        $emptyEditor = new Editor($emptySource, $emptyResult);
        $emptyEditor->insertTopLevel("\r\n");
        self::assertSame([], $emptyEditor->patches());

        $blankTerminated = "Paragraph.\n\n";
        [$blankSource, $blankResult] = self::parse($blankTerminated);
        self::assertSame(
            "Paragraph.\n\nNext.\n",
            new Editor($blankSource, $blankResult)->insertTopLevel('Next.')->toRst(),
        );

        [$unterminatedSource, $unterminatedResult] = self::parse('Paragraph.');
        self::assertSame(
            "Paragraph.\n\nNext.",
            new Editor($unterminatedSource, $unterminatedResult)->insertTopLevel('Next.')->toRst(),
        );
    }

    /**
     * @return array{Source, ParseResult}
     */
    private static function parse(string $rst, ?Profile $profile = null): array
    {
        $source = Source::fromString($rst);

        return [$source, new BlockParser()->parse($source, $profile)];
    }

    private static function section(ParseResult $result): Section
    {
        $section = $result->document()->children()[0] ?? null;
        self::assertInstanceOf(Section::class, $section);

        return $section;
    }

    /**
     * @template T of Node
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    private static function node(ParseResult $result, string $type): Node
    {
        foreach ($result->document()->descendants() as $node) {
            if ($node instanceof $type) {
                return $node;
            }
        }

        self::fail(\sprintf('Expected a node of type %s.', $type));
    }

    private static function targetNamed(ParseResult $result, string $name): HyperlinkTarget
    {
        foreach ($result->document()->descendants() as $node) {
            if ($node instanceof HyperlinkTarget && $name === $node->name) {
                return $node;
            }
        }

        self::fail(\sprintf('Expected a target named "%s".', $name));
    }
}
