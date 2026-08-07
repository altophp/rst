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

namespace Alto\Rst\Lint;

use Alto\Rst\Node\ContainerNode;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\DirectiveBodyKind;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Table;
use Alto\Rst\Node\Text;
use Alto\Rst\Reference\ReferenceGraph;

/**
 * Traverses typed inline nodes without running the inline parser again.
 *
 * Simple-table paragraphs use the graph's segmented cache entry. This
 * preserves the table-cell rectangle workaround recorded in DECISIONS.md.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class InlineNodeTraversal
{
    /**
     * @return \Generator<int, Node>
     */
    public function walk(Document $document, ReferenceGraph $references): \Generator
    {
        foreach ($document->children() as $node) {
            yield from $this->walkBlock($node, $references, false);
        }
    }

    /**
     * @return \Generator<int, Node>
     */
    private function walkBlock(Node $node, ReferenceGraph $references, bool $perSegment): \Generator
    {
        if ($node instanceof Directive) {
            if (DirectiveBodyKind::Blocks === $node->bodyKind) {
                foreach ($node->children() as $child) {
                    yield from $this->walkBlock($child, $references, false);
                }

                return;
            }

            foreach ($references->directiveInlineNodes($node) ?? [] as $inline) {
                yield from $this->walkInline($inline);
            }

            return;
        }

        if ($node instanceof Text) {
            foreach ($references->inlineNodes($node, $perSegment) ?? [] as $inline) {
                yield from $this->walkInline($inline);
            }

            return;
        }

        if ($node instanceof Table) {
            foreach ($node->children() as $row) {
                foreach ($row->children() as $cell) {
                    foreach ($cell->children() as $child) {
                        yield from $this->walkBlock($child, $references, $child instanceof Paragraph);
                    }
                }
            }

            return;
        }

        if (!$node instanceof ContainerNode) {
            return;
        }

        foreach ($node->children() as $child) {
            yield from $this->walkBlock(
                $child,
                $references,
                $perSegment && $node instanceof Paragraph,
            );
        }
    }

    /**
     * @return \Generator<int, Node>
     */
    private function walkInline(Node $node): \Generator
    {
        yield $node;

        if (!$node instanceof ContainerNode) {
            return;
        }

        foreach ($node->children() as $child) {
            yield from $this->walkInline($child);
        }
    }
}
