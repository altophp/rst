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

use Alto\Rst\Node\ContainerNode;
use Alto\Rst\Node\Inline\CitationReference;
use Alto\Rst\Node\Inline\Emphasis;
use Alto\Rst\Node\Inline\FootnoteReference;
use Alto\Rst\Node\Inline\HyperlinkReference;
use Alto\Rst\Node\Inline\InlineLiteral;
use Alto\Rst\Node\Inline\InlineTarget;
use Alto\Rst\Node\Inline\InlineText;
use Alto\Rst\Node\Inline\InterpretedText;
use Alto\Rst\Node\Inline\StandaloneHyperlink;
use Alto\Rst\Node\Inline\Strong;
use Alto\Rst\Node\Inline\SubstitutionReference;
use Alto\Rst\Node\Node;
use Alto\Rst\Parser\InlineParser;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use PHPUnit\Framework\TestCase;

abstract class InlineParserTestCase extends TestCase
{
    /**
     * @return list<Node>
     */
    protected static function parseInline(string $text, int $baseOffset = 0): array
    {
        return new InlineParser(new ProblemCollector())->parse($text, $baseOffset);
    }

    /**
     * A compact structural rendering, so recognition tables stay readable.
     */
    protected static function outline(string $text, int $baseOffset = 0): string
    {
        return self::render(self::parseInline($text, $baseOffset));
    }

    /**
     * @return list<Problem>
     */
    protected static function parseProblems(string $text, int $baseOffset = 0): array
    {
        $problems = new ProblemCollector();
        new InlineParser($problems)->parse($text, $baseOffset);

        return $problems->report()->problems();
    }

    /**
     * @return list<string>
     */
    protected static function problemCodes(string $text): array
    {
        return array_map(
            static fn(Problem $problem): string => $problem->code,
            self::parseProblems($text),
        );
    }

    protected static function assertNoProblems(string $text): void
    {
        self::assertSame([], self::problemCodes($text));
    }

    /**
     * @template T of Node
     *
     * @param list<Node>      $nodes
     * @param class-string<T> $type
     *
     * @return T
     */
    protected static function nodeAt(array $nodes, int $index, string $type): Node
    {
        $node = $nodes[$index] ?? null;

        self::assertInstanceOf($type, $node);

        return $node;
    }

    /**
     * @return array{int, int}
     */
    protected static function bounds(Node $node): array
    {
        return [$node->span()->start, $node->span()->end()];
    }

    /**
     * @param list<Node> $nodes
     */
    private static function render(array $nodes): string
    {
        $rendered = '';

        foreach ($nodes as $node) {
            $rendered .= self::renderNode($node);
        }

        return $rendered;
    }

    private static function renderNode(Node $node): string
    {
        return match (true) {
            $node instanceof InlineText => \sprintf('text(%s)', $node->text),
            $node instanceof Emphasis => \sprintf('em(%s)', self::renderChildren($node)),
            $node instanceof Strong => \sprintf('strong(%s)', self::renderChildren($node)),
            $node instanceof InlineLiteral => \sprintf('literal(%s)', $node->text),
            $node instanceof InterpretedText => \sprintf(
                'interpreted(%s,%s,%s)',
                $node->role ?? '-',
                $node->text,
                $node->rolePrefix ? 'prefix' : 'suffix',
            ),
            $node instanceof SubstitutionReference => \sprintf(
                'substitution(%s%s%s)',
                $node->name,
                $node->reference ? ',ref' : '',
                $node->anonymous ? ',anonymous' : '',
            ),
            $node instanceof FootnoteReference => \sprintf('footnote(%s)', $node->label),
            $node instanceof CitationReference => \sprintf('citation(%s)', $node->label),
            $node instanceof HyperlinkReference => \sprintf(
                'reference(%s%s%s%s)',
                $node->text,
                null === $node->embeddedUri ? '' : ',uri=' . $node->embeddedUri,
                $node->anonymous ? ',anonymous' : '',
                $node->simple ? ',simple' : '',
            ),
            $node instanceof StandaloneHyperlink => \sprintf('standalone(%s)', $node->uri),
            $node instanceof InlineTarget => \sprintf('target(%s)', $node->name),
            default => throw new \LogicException($node::class . ' is not an inline node.'),
        };
    }

    private static function renderChildren(ContainerNode $node): string
    {
        return self::render(array_values($node->children()));
    }
}
