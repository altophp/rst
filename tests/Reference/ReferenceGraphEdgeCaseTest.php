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

namespace Alto\Rst\Tests\Reference;

use Alto\Rst\Node\Directive;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\SubstitutionDefinition;
use Alto\Rst\Reference\DefinitionKind;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Reference\ReferenceGraphBuilder;
use Alto\Rst\Reference\ReferenceStatus;
use Alto\Rst\Reference\ReferenceType;
use Alto\Rst\Reference\SubstitutionKind;
use Alto\Rst\Rst;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReferenceGraph::class)]
#[CoversClass(ReferenceGraphBuilder::class)]
final class ReferenceGraphEdgeCaseTest extends TestCase
{
    public function testEmptyNamedTargetChainsToTheFollowingExternalTarget(): void
    {
        $graph = self::sphinx(
            "See alias_.\n\n"
            .".. _alias:\n"
            .".. _final: https://example.com/final\n",
        );
        $reference = $graph->references()[0];

        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertSame('https://example.com/final', $reference->target?->destination);
    }

    public function testEmptyAnonymousTargetAnchorsTheFollowingParagraph(): void
    {
        $graph = self::docutils(
            "`Jump`__\n\n"
            .".. __:\n\n"
            ."Destination paragraph.\n",
        );
        $reference = $graph->references()[0];

        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertSame(DefinitionKind::Hyperlink, $reference->target?->kind);
        self::assertNotNull($reference->target);
        self::assertInstanceOf(Paragraph::class, $graph->anchorNode($reference->target));
    }

    public function testEmptyAnonymousTargetChainsToTheFollowingAnonymousUrl(): void
    {
        $graph = self::docutils(
            "`Jump`__\n\n"
            .".. __:\n"
            .".. __: https://example.com/final\n",
        );
        $reference = $graph->references()[0];

        self::assertSame(ReferenceStatus::Unresolved, $reference->status);
        self::assertSame('reference/anonymous-mismatch', $graph->problems()->problems()[0]->code);
    }

    public function testReferencesInsideListsAndDefinitionListsAreCollected(): void
    {
        $graph = self::docutils(
            "* list-target_\n\n"
            ."Term term-target_ : classifier classifier-target_\n"
            ."    Definition definition-target_.\n\n"
            .".. _list-target: https://example.com/list\n"
            .".. _term-target: https://example.com/term\n"
            .".. _classifier-target: https://example.com/classifier\n"
            .".. _definition-target: https://example.com/definition\n",
        );

        self::assertSame(
            ['list-target', 'term-target', 'classifier-target', 'definition-target'],
            array_map(static fn ($reference): string => $reference->label, $graph->references()),
        );
        self::assertSame([], $graph->unresolved());
    }

    public function testReferencesInsideNestedTableCellBlocksAreCollected(): void
    {
        $graph = self::docutils(
            "+---+------------+\n"
            ."| A | - target_  |\n"
            ."+---+------------+\n\n"
            .".. _target: https://example.com\n",
        );

        self::assertSame(ReferenceStatus::Resolved, $graph->references()[0]->status);
    }

    public function testRoleReferencesAreIgnoredWithoutAProfile(): void
    {
        $source = "See :ref:`target`.\n";
        $document = Rst::sphinx()->parse($source)->document();
        $graph = ReferenceGraph::fromDocument($document, Source::fromString($source));

        self::assertSame([], $graph->references());
    }

    public function testSubstitutionInASectionTitleKeepsTheRawImplicitName(): void
    {
        $graph = self::docutils(
            ".. |product| replace:: Alto\n\n"
            ."See `|product|`_.\n\n"
            ."|product|\n"
            ."=========\n",
        );
        $reference = $graph->references(ReferenceType::Hyperlink)[0];

        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertSame('|product|', $reference->target?->name);
    }

    public function testTargetTitlesFlattenEveryReaderVisibleInlineForm(): void
    {
        $title = '*Em* **Strong** ``code`` `Link <https://e.test>`_ '
            .'https://x.test _`spot` [1]_ [CIT]_ |brand|';
        $graph = self::docutils(
            ".. |brand| replace:: Alto\n\n"
            .$title."\n"
            .str_repeat('=', \strlen($title))."\n\n"
            .".. [1] Note.\n"
            .".. [CIT] Citation.\n",
        );
        $definition = $graph->definitions(DefinitionKind::Section)[0];

        self::assertSame(
            'Em Strong code Link https://x.test spot [1] [CIT] Alto',
            $graph->targetTitle($definition),
        );
    }

    public function testTargetTitleKeepsAnUnresolvedSubstitutionVisible(): void
    {
        $graph = self::docutils("|missing|\n=========\n");
        $definition = $graph->definitions(DefinitionKind::Section)[0];

        self::assertSame('|missing|', $graph->targetTitle($definition));
    }

    public function testNonSectionTargetTitleUsesItsDeclaredName(): void
    {
        $graph = self::docutils(".. _website: https://example.com\n");
        $definition = $graph->definitions(DefinitionKind::Hyperlink)[0];

        self::assertSame('website', $graph->targetTitle($definition));
    }

    public function testMissingAutomaticNotesAndDuplicateCitationsAreTyped(): void
    {
        $graph = self::docutils(
            "Missing [#]_, [*]_, and ambiguous [CIT]_.\n\n"
            .".. [CIT] First.\n"
            .".. [cit] Second.\n",
        );
        $references = $graph->references();

        self::assertSame(ReferenceStatus::Unresolved, $references[0]->status);
        self::assertSame(ReferenceStatus::Unresolved, $references[1]->status);
        self::assertSame(ReferenceStatus::Ambiguous, $references[2]->status);
    }

    public function testUndefinedNestedSubstitutionPropagatesItsStatus(): void
    {
        $graph = self::docutils(
            ".. |outer| replace:: Before |missing| after\n\n"
            ."Use |outer|.\n",
        );
        $outer = array_values(array_filter(
            $graph->references(ReferenceType::Substitution),
            static fn ($reference): bool => 'outer' === $reference->label,
        ))[0];

        self::assertSame(ReferenceStatus::Unresolved, $outer->status);
        self::assertNull($outer->target);
    }

    public function testLinkedAnonymousSubstitutionKeepsBothSemantics(): void
    {
        $graph = self::docutils(
            ".. |label| replace:: **Link**\n\n"
            ."Use |label|__.\n\n"
            .".. __: https://example.com\n",
        );

        self::assertSame(ReferenceStatus::Resolved, $graph->references(ReferenceType::Substitution)[0]->status);
        self::assertSame(ReferenceStatus::Resolved, $graph->references(ReferenceType::Hyperlink)[0]->status);
    }

    public function testNestedLinkedSubstitutionKeepsTheLinkSuffix(): void
    {
        $graph = self::docutils(
            ".. |inner| replace:: destination\n"
            .".. |outer| replace:: **Before |inner|_ after**\n\n"
            ."Use |outer|.\n\n"
            .".. _destination: https://example.com\n",
        );
        $outer = array_values(array_filter(
            $graph->references(ReferenceType::Substitution),
            static fn ($reference): bool => 'outer' === $reference->label,
        ))[0];

        self::assertSame('**Before destination_ after**', $outer->target?->destination);
    }

    public function testReplacementCombinesItsArgumentAndBody(): void
    {
        $graph = self::docutils(
            ".. |message| replace:: first\n"
            ."   second\n\n"
            ."Use |message|.\n",
        );
        $definition = $graph->definitions(DefinitionKind::Substitution)[0];

        self::assertSame("first\nsecond", $definition->destination);
    }

    public function testTargetEndingInUnderscoreIsNotAlwaysAnAlias(): void
    {
        $graph = self::docutils(".. _target: ?invalid_\n");
        $definition = $graph->definitions(DefinitionKind::Hyperlink)[0];

        self::assertSame('?invalid_', $definition->destination);
    }

    public function testStandardSubstitutionVariantsKeepTheirMetadata(): void
    {
        $graph = self::docutils(
            ".. |left| unicode:: 0x20 0xA9\n"
            ."   :ltrim:\n"
            .".. |right| unicode:: 0xA9 0x20\n"
            ."   :rtrim:\n"
            .".. |logo| image:: logo.svg\n"
            ."   :alt: Project logo\n\n"
            ."Use |left| |right| |logo|.\n",
        );
        $definitions = $graph->definitions(DefinitionKind::Substitution);

        self::assertSame("\u{00A9}", $definitions[0]->destination);
        self::assertSame("\u{00A9}", $definitions[1]->destination);
        self::assertSame(SubstitutionKind::Image, $definitions[2]->substitutionKind);
        self::assertSame('Project logo', $definitions[2]->substitutionAlt);
    }

    public function testLiteralSubstitutionExpansionUsesTheSizeBudget(): void
    {
        $replacement = str_repeat('x', 1_048_577);
        $graph = self::docutils(
            '.. |huge| replace:: '.$replacement."\n\n"
            ."Use |huge|.\n",
        );
        $reference = $graph->references(ReferenceType::Substitution)[0];

        self::assertSame(ReferenceStatus::Unresolved, $reference->status);
        self::assertSame('substitution/expansion-limit', $graph->problems()->problems()[0]->code);
    }

    public function testManualSubstitutionWithoutASourceArgumentKeepsANullSpan(): void
    {
        $source = Source::fromString('placeholder');
        $span = ByteSpan::of(0, \strlen($source->bytes));
        $directive = new Directive($span, 'replace', ['missing']);
        $definition = new SubstitutionDefinition($span, 'name', $directive);
        $graph = ReferenceGraph::fromDocument(new Document($span, [$definition]), $source);
        $registered = $graph->definitions(DefinitionKind::Substitution)[0];

        self::assertSame('missing', $registered->destination);
        self::assertNull($registered->destinationSpan);
    }

    public function testLegacyDirectiveBodiesRejectNonuniformAndBlockContent(): void
    {
        $multiline = "    first\n\n    second_";
        $source = Source::fromString($multiline);
        $span = ByteSpan::of(0, \strlen($multiline));
        $directive = new Directive($span, 'note', rawBody: $span);
        $graph = ReferenceGraph::fromDocument(new Document($span, [$directive]), $source);

        self::assertTrue($graph->isCoverageComplete());
        self::assertCount(1, $graph->references());

        foreach (["    first\n      nested_", "    first\n    * list_"] as $bytes) {
            $source = Source::fromString($bytes);
            $span = ByteSpan::of(0, \strlen($bytes));
            $directive = new Directive($span, 'note', rawBody: $span);
            $graph = ReferenceGraph::fromDocument(new Document($span, [$directive]), $source);

            self::assertFalse($graph->isCoverageComplete());
            self::assertSame([], $graph->references());
        }
    }

    private static function docutils(string $source): ReferenceGraph
    {
        return Rst::docutils()->parse($source)->references();
    }

    private static function sphinx(string $source): ReferenceGraph
    {
        return Rst::sphinx()->parse($source)->references();
    }
}
