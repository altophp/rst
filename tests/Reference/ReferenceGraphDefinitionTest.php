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

use Alto\Rst\Reference\DefinitionKind;
use Alto\Rst\Reference\ReferenceDefinition;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Reference\ReferenceGraphBuilder;
use Alto\Rst\Reference\ReferenceOccurrence;
use Alto\Rst\Reference\ReferenceStatus;
use Alto\Rst\Reference\ReferenceType;
use Alto\Rst\Rst;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReferenceGraph::class)]
#[CoversClass(ReferenceGraphBuilder::class)]
#[CoversClass(ReferenceDefinition::class)]
#[CoversClass(ReferenceOccurrence::class)]
final class ReferenceGraphDefinitionTest extends TestCase
{
    public function testNumberedFootnoteReferenceResolvesItsDefinition(): void
    {
        $reference = self::graph("Note [1]_.\n\n.. [1] Body.\n")->references()[0];

        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertSame(DefinitionKind::Footnote, $reference->target?->kind);
        self::assertSame('1', $reference->displayLabel);
    }

    public function testCitationResolutionIsCaseInsensitive(): void
    {
        $reference = self::graph("See [cit2002]_.\n\n.. [CIT2002] Citation.\n")->references()[0];

        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertSame('CIT2002', $reference->target?->name);
    }

    public function testSubstitutionReferenceResolvesReplacementDefinition(): void
    {
        $reference = self::graph("Uses |project name|.\n\n.. |Project   Name| replace:: Alto Rst\n")->references()[0];

        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertInstanceOf(ReferenceDefinition::class, $reference->target);
        self::assertSame(DefinitionKind::Substitution, $reference->target->kind);
        self::assertSame('Alto Rst', $reference->target->destination);
    }

    public function testUndefinedTypedReferenceHasATypedProblem(): void
    {
        $graph = self::graph("Missing [CIT]_.\n");
        $reference = $graph->references()[0];

        self::assertSame(ReferenceStatus::Unresolved, $reference->status);
        self::assertSame('citation/unresolved-target', $graph->problems()->problems()[0]->code);
    }

    public function testDuplicateSubstitutionUsesTheLastDefinition(): void
    {
        $graph = self::graph(
            "Uses |name|.\n\n"
            . ".. |name| replace:: first\n"
            . ".. |name| replace:: second\n",
        );

        self::assertSame('second', $graph->references()[0]->target?->destination);
        self::assertSame('substitution/duplicate-definition', $graph->problems()->problems()[0]->code);
    }

    public function testUnlabelledAutoFootnotesPairByOrderAndSkipReservedNumbers(): void
    {
        $graph = self::graph(
            "See [#]_ then [#]_ and [2]_.\n\n"
            . ".. [#] First auto.\n"
            . ".. [2] Explicit.\n"
            . ".. [#] Second auto.\n",
        );

        self::assertSame(
            ['1', '3', '2'],
            array_map(static fn($reference): ?string => $reference->displayLabel, $graph->references()),
        );
    }

    public function testLabelledAutoFootnoteKeepsOneAssignedNumber(): void
    {
        $graph = self::graph("See [#named]_ twice [#named]_.\n\n.. [#named] Body.\n");

        self::assertSame('1', $graph->references()[0]->displayLabel);
        self::assertSame('1', $graph->references()[1]->displayLabel);
        self::assertSame($graph->references()[0]->target, $graph->references()[1]->target);
    }

    public function testSymbolFootnotesPairByOrder(): void
    {
        $graph = self::graph("See [*]_ then [*]_.\n\n.. [*] First.\n.. [*] Second.\n");

        self::assertSame('*', $graph->references()[0]->displayLabel);
        self::assertSame("\u{2020}", $graph->references()[1]->displayLabel);
    }

    public function testSymbolFootnotesUseTheFullDocutilsSequence(): void
    {
        $source = implode(' ', array_fill(0, 11, '[*]_')) . "\n\n";

        for ($index = 0; $index < 11; ++$index) {
            $source .= ".. [*] Symbol.\n";
        }

        self::assertSame(
            ['*', "\u{2020}", "\u{2021}", "\u{00A7}", "\u{00B6}", '#', "\u{2660}", "\u{2665}", "\u{2666}", "\u{2663}", '**'],
            array_map(static fn($reference): ?string => $reference->displayLabel, self::graph($source)->references()),
        );
    }

    public function testNestedSubstitutionsExpandRecursively(): void
    {
        $graph = self::graph(
            ".. |outer| replace:: Before |inner| after\n"
            . ".. |inner| replace:: middle\n\n"
            . "See |outer|.\n",
        );
        $outer = $graph->references()[1];

        self::assertSame(ReferenceStatus::Resolved, $outer->status);
        self::assertSame('Before middle after', $outer->target?->destination);
    }

    public function testCircularSubstitutionIsTypedAndReported(): void
    {
        $graph = self::graph(
            ".. |one| replace:: |two|\n"
            . ".. |two| replace:: |one|\n\n"
            . "See |one|.\n",
        );
        $reference = $graph->references()[2];

        self::assertSame(ReferenceStatus::Circular, $reference->status);
        self::assertSame('substitution/circular-target', $graph->problems()->problems()[0]->code);
    }

    public function testReferenceIntroducedBySubstitutionKeepsItsOriginalArgumentSpan(): void
    {
        $source = ".. |site| replace:: `Example`_\n"
            . ".. _Example: https://example.com/\n\n"
            . "Visit |site|.\n";
        $graph = self::graph($source);
        $introduced = $graph->references()[0];

        self::assertSame(ReferenceType::Hyperlink, $introduced->type);
        self::assertSame(strpos($source, '`Example`_'), $introduced->span->start);
        self::assertSame(\strlen('`Example`_'), $introduced->span->length);
        self::assertSame('https://example.com/', $introduced->target?->destination);
    }

    public function testEscapedAndLiteralPipesDoNotTriggerNestedExpansion(): void
    {
        $graph = self::graph(
            ".. |safe| replace:: \\|missing| and ``|also-missing|``\n\n"
            . "Use |safe|.\n",
        );
        $reference = $graph->references(ReferenceType::Substitution)[0];

        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertSame('\\|missing| and ``|also-missing|``', $reference->target?->destination);
        self::assertFalse($graph->problems()->hasProblems());
    }

    public function testSubstitutionExpansionHasASizeBudget(): void
    {
        $source = '';

        for ($index = 0; $index < 24; ++$index) {
            $source .= sprintf(".. |s%d| replace:: |s%d| |s%d|\n", $index, $index + 1, $index + 1);
        }

        $source .= ".. |s24| replace:: x\n\nUse |s0|.\n";
        $graph = self::graph($source);

        self::assertSame(ReferenceStatus::Unresolved, $graph->references(ReferenceType::Substitution)[0]->status);
        self::assertContains(
            'substitution/expansion-limit',
            array_map(static fn($problem): string => $problem->code, $graph->problems()->problems()),
        );
    }

    public function testUnicodeSubstitutionsDecodeSupportedNumericForms(): void
    {
        $graph = self::graph(
            ".. |chars| unicode:: U+0041 &#x42; 67 0x20AC U+1F600\n\n"
            . "Use |chars|.\n",
        );

        self::assertSame(
            "ABC\u{20AC}\u{1F600}",
            $graph->references(ReferenceType::Substitution)[0]->target?->destination,
        );
    }

    public function testUnicodeSubstitutionTrimOptionsAreApplied(): void
    {
        $graph = self::graph(
            ".. |chars| unicode:: 0x20 0x41 0x20\n"
            . "   :trim:\n\n"
            . "Use |chars|.\n",
        );

        self::assertSame('A', $graph->references(ReferenceType::Substitution)[0]->target?->destination);
    }

    public function testInvalidAndUnsupportedSubstitutionsAreReported(): void
    {
        $invalid = self::graph(".. |bad| unicode:: 0x110000\n\nUse |bad|.\n");
        $unsupported = self::graph(".. |today| date::\n\nUse |today|.\n");

        self::assertSame('substitution/invalid-unicode', $invalid->problems()->problems()[0]->code);
        self::assertSame('substitution/unsupported-directive', $unsupported->problems()->problems()[0]->code);
    }

    public function testSubstitutionExpansionHasADepthBudget(): void
    {
        $source = '';

        for ($index = 0; $index < 260; ++$index) {
            $source .= sprintf(".. |s%d| replace:: |s%d|\n", $index, $index + 1);
        }

        $source .= ".. |s260| replace:: x\n\nUse |s0|.\n";
        $graph = self::graph($source);

        self::assertContains(
            'substitution/expansion-limit',
            array_map(static fn($problem): string => $problem->code, $graph->problems()->problems()),
        );
    }

    public function testGraphQuerySurfaceKeepsTypedIndexes(): void
    {
        $source = Source::fromString(
            "See |name|_ and missing_.\n\n"
            . ".. |name| replace:: Name\n"
            . ".. _name: https://example.com/\n",
        );
        $document = Rst::docutils()->parse($source->bytes)->document();
        $graph = ReferenceGraph::fromDocument($document, $source, Rst::docutils()->profile());
        $substitution = $graph->references(ReferenceType::Substitution)[0];

        self::assertCount(2, $graph->referencesAt($substitution->span));
        self::assertSame($substitution, $graph->referenceAt($substitution->span, ReferenceType::Substitution));
        self::assertNull($graph->referenceAt(ByteSpan::of(999, 0)));
        self::assertCount(1, $graph->unresolved());
        self::assertCount(1, $graph->definitions(DefinitionKind::Substitution));
        self::assertArrayHasKey('name', $graph->explicitTargets());
        self::assertArrayHasKey('name', $graph->explicitDefinitions());
        $target = $graph->explicitTarget('name');
        self::assertNotNull($target);
        self::assertNull($graph->displayLabel($target));
    }

    private static function graph(string $source): ReferenceGraph
    {
        return Rst::docutils()->parse($source)->references();
    }
}
