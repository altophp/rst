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

namespace Alto\Rst\Tests\Lint;

use Alto\Rst\Lint\ContextRule;
use Alto\Rst\Lint\InlineNodeTraversal;
use Alto\Rst\Lint\LintConfig;
use Alto\Rst\Lint\LintContext;
use Alto\Rst\Lint\Linter;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\DirectiveBodyKind;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\Inline\Emphasis;
use Alto\Rst\Node\Inline\HyperlinkReference;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Transition;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Rst;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LintContext::class)]
#[CoversClass(InlineNodeTraversal::class)]
final class LintContextTest extends TestCase
{
    public function testTraversalReusesTheProvidedGraphInlineNodeInstances(): void
    {
        $rst = "See cached_.\n";
        $source = Source::fromString($rst);
        $result = Rst::docutils()->parse($rst);
        $paragraph = $result->document()->children()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        $cached = $result->references()->inlineNodes($paragraph->text, false);
        self::assertNotNull($cached);

        $rule = new readonly class($cached) implements ContextRule {
            /**
             * @param list<Node> $expected
             */
            public function __construct(
                private array $expected,
            ) {
            }

            public function code(): string
            {
                return 'test/cached-inline-nodes';
            }

            public function check(LintContext $context, ProblemCollector $problems): void
            {
                Assert::assertSame($this->expected, iterator_to_array($context->inlineNodes(), false));
            }
        };

        new Linter()->lint(
            $result->document(),
            LintConfig::recommended()->withRule($rule),
            $source,
            $result->references(),
        );
    }

    public function testTraversalUsesSegmentedTableCacheWithoutCrossCellMarkup(): void
    {
        $rst = "========  ==========\n"
            ."col       text\n"
            ."========  ==========\n"
            ."first     `target\n"
            ."          link`_\n"
            ."========  ==========\n";
        $result = Rst::docutils()->parse($rst);
        $nodes = iterator_to_array(
            new InlineNodeTraversal()->walk($result->document(), $result->references()),
            false,
        );

        self::assertSame(
            [],
            array_values(array_filter($nodes, static fn (Node $node): bool => $node instanceof HyperlinkReference)),
        );
    }

    public function testStructuredDirectiveTraversalReusesTheProvidedGraphWithoutReparsing(): void
    {
        $rst = ".. note::\n\n    See cached_.\n";
        $source = Source::fromString($rst);
        $result = Rst::docutils()->parse($rst);
        $directive = $result->document()->children()[0];
        self::assertInstanceOf(Directive::class, $directive);
        $paragraph = $directive->children()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        $cached = $result->references()->inlineNodes($paragraph->text, false);
        self::assertNotNull($cached);

        $rule = new readonly class($result->references(), $cached) implements ContextRule {
            /**
             * @param list<Node> $expected
             */
            public function __construct(
                private ReferenceGraph $graph,
                private array $expected,
            ) {
            }

            public function code(): string
            {
                return 'test/provided-directive-inline-nodes';
            }

            public function check(LintContext $context, ProblemCollector $problems): void
            {
                Assert::assertSame($this->graph, $context->references);
                Assert::assertSame($this->expected, iterator_to_array($context->inlineNodes(), false));
            }
        };

        new Linter()->lint(
            $result->document(),
            LintConfig::recommended()->withRule($rule),
            $source,
            $result->references(),
        );
    }

    public function testTraversalKeepsByteSpansForReferencesInTableSegments(): void
    {
        $rst = "========  ==========\n"
            ."col       text\n"
            ."========  ==========\n"
            ."first     one_\n"
            ."          two_\n"
            ."========  ==========\n";
        $result = Rst::docutils()->parse($rst);
        $links = array_values(array_filter(
            iterator_to_array(
                new InlineNodeTraversal()->walk($result->document(), $result->references()),
                false,
            ),
            static fn (Node $node): bool => $node instanceof HyperlinkReference,
        ));

        self::assertCount(2, $links);
        self::assertSame(strpos($rst, 'one_'), $links[0]->span()->start);
        self::assertSame(strpos($rst, 'two_'), $links[1]->span()->start);
    }

    public function testTraversalCoversLegacyDirectiveInlineTreesAndLeafBlocks(): void
    {
        $rst = '    *nested* and target_';
        $source = Source::fromString($rst);
        $span = ByteSpan::of(0, \strlen($rst));
        $directive = new Directive(
            $span,
            'note',
            rawBody: $span,
            bodyKind: DirectiveBodyKind::Opaque,
        );
        $document = new Document($span, [$directive, new Transition(ByteSpan::of(0, 1))]);
        $references = ReferenceGraph::fromDocument($document, $source);
        $nodes = iterator_to_array(
            new InlineNodeTraversal()->walk($document, $references),
            false,
        );

        self::assertTrue((bool) array_filter($nodes, static fn (Node $node): bool => $node instanceof Emphasis));
        self::assertTrue((bool) array_filter($nodes, static fn (Node $node): bool => $node instanceof HyperlinkReference));
    }
}
