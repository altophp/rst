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
use Alto\Rst\Node\Inline\HyperlinkReference;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Reference\ReferenceGraphBuilder;
use Alto\Rst\Reference\ReferenceStatus;
use Alto\Rst\Rst;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReferenceGraph::class)]
#[CoversClass(ReferenceGraphBuilder::class)]
final class ReferenceGraphDirectiveTest extends TestCase
{
    public function testSimpleAdmonitionBodyContributesReferences(): void
    {
        $source = ".. note::\n\n"
            ."    See used_ and `bad <javascript:go>`_.\n\n"
            .".. _used: https://ok.test\n";
        $result = Rst::docutils()->parse($source);
        $graph = $result->references();

        self::assertTrue($graph->isCoverageComplete());
        self::assertCount(2, $graph->references());
        $byLabel = [];

        foreach ($graph->references() as $reference) {
            $byLabel[$reference->label] = $reference;
        }

        self::assertSame(ReferenceStatus::Resolved, $byLabel['used']->status);
        self::assertSame('https://ok.test', $byLabel['used']->target?->destination);
        self::assertSame('javascript:go', $byLabel['bad']->target?->destination);
        self::assertSame([], $graph->unusedDefinitions());

        $directive = $result->document()->children()[0];
        self::assertInstanceOf(Directive::class, $directive);
        $paragraph = $directive->children()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertContainsOnlyInstancesOf(
            HyperlinkReference::class,
            array_values(array_filter(
                $graph->inlineNodes($paragraph->text, false) ?? [],
                static fn ($node): bool => $node instanceof HyperlinkReference,
            )),
        );
        self::assertNull($graph->directiveInlineNodes($directive));
    }

    public function testUniformlyIndentedMultilineAdmonitionBodyContributesReferences(): void
    {
        $source = ".. note::\n\n"
            ."    First line.\n"
            ."    See used_ and `bad <javascript:go>`_.\n\n"
            .".. _used: https://ok.test\n";
        $graph = Rst::docutils()->parse($source)->references();
        $byLabel = [];

        foreach ($graph->references() as $reference) {
            $byLabel[$reference->label] = $reference;
        }

        self::assertTrue($graph->isCoverageComplete());
        self::assertSame(ReferenceStatus::Resolved, $byLabel['used']->status);
        self::assertSame('javascript:go', $byLabel['bad']->target?->destination);
        self::assertSame(strpos($source, '`bad'), $byLabel['bad']->span->start);
    }

    #[DataProvider('opaqueDirectiveNames')]
    public function testOpaqueDirectiveDoesNotProduceFalseReferences(string $name): void
    {
        $source = \sprintf(".. %s:: text\n\n", $name)
            ."    See fake_ and `bad <javascript:go>`_.\n";
        $graph = Rst::docutils()->parse($source)->references();

        self::assertFalse($graph->isCoverageComplete());
        self::assertSame([], $graph->references());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function opaqueDirectiveNames(): iterable
    {
        yield 'CSV table' => ['csv-table'];
        yield 'parsed literal' => ['parsed-literal'];
        yield 'raw' => ['raw'];
        yield 'configuration block' => ['configuration-block'];
        yield 'literal include' => ['literalinclude'];
    }

    public function testNestedStructuredDirectiveBodyContributesReferences(): void
    {
        $source = ".. note::\n\n"
            ."    .. warning::\n\n"
            ."        See nested_.\n\n"
            .".. _nested: https://example.com\n";
        $result = Rst::docutils()->parse($source);
        $graph = $result->references();

        self::assertTrue($graph->isCoverageComplete());
        self::assertCount(1, $graph->references());
        self::assertSame(ReferenceStatus::Resolved, $graph->references()[0]->status);
        self::assertSame(strpos($source, 'nested_'), $graph->references()[0]->span->start);
        $outer = $result->document()->children()[0];
        self::assertInstanceOf(Directive::class, $outer);
        self::assertContainsOnlyInstancesOf(Directive::class, $outer->children());
    }

    public function testKnownLiteralDirectiveBodyIsCompleteAndNotParsedAsProse(): void
    {
        $source = ".. code:: text\n\n    fake_ and `bad <javascript:go>`_.\n";
        $graph = Rst::docutils()->parse($source)->references();

        self::assertTrue($graph->isCoverageComplete());
        self::assertSame([], $graph->references());
    }

    public function testUnexpectedBodyOnBodylessDirectiveMakesCoverageIncomplete(): void
    {
        $source = ".. image:: image.png\n\n    fake_.\n";
        $graph = Rst::docutils()->parse($source)->references();

        self::assertFalse($graph->isCoverageComplete());
        self::assertSame([], $graph->references());
    }

    public function testLegacyOpaqueAdmonitionKeepsItsInlineBodyCompatibility(): void
    {
        $bytes = '    See legacy_.';
        $source = Source::fromString($bytes);
        $span = ByteSpan::of(0, \strlen($bytes));
        $directive = new Directive($span, 'note', rawBody: $span);
        $document = new Document($span, [$directive]);
        $graph = ReferenceGraph::fromDocument($document, $source);

        self::assertTrue($graph->isCoverageComplete());
        self::assertCount(1, $graph->references());
        self::assertSame('legacy', $graph->references()[0]->label);
        self::assertNotNull($graph->directiveInlineNodes($directive));
    }

    public function testDirectiveReferenceSpansUseOriginalUtf8ByteOffsets(): void
    {
        $source = ".. note::\n\n"
            ."    Échec used_ and `bad <javascript:go>`_.\n\n"
            .".. _used: https://ok.test\n";
        $references = Rst::docutils()->parse($source)->references()->references();
        $byLabel = [];

        foreach ($references as $reference) {
            $byLabel[$reference->label] = $reference;
        }

        self::assertSame(strpos($source, 'used_'), $byLabel['used']->span->start);
        self::assertSame(\strlen('used_'), $byLabel['used']->span->length);
        self::assertSame(strpos($source, '`bad'), $byLabel['bad']->span->start);
        self::assertSame(\strlen('`bad <javascript:go>`_'), $byLabel['bad']->span->length);
    }
}
