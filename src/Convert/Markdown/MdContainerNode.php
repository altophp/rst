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

namespace Alto\Rst\Convert\Markdown;

/**
 * Base type of Markdown nodes holding an ordered list of child nodes.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
abstract readonly class MdContainerNode extends MdNode
{
    /**
     * @return list<MdNode>
     */
    abstract public function children(): array;

    /**
     * Walks all descendant nodes depth-first, in document order.
     *
     * @return \Generator<int, MdNode>
     */
    final public function descendants(): \Generator
    {
        foreach ($this->children() as $child) {
            yield $child;

            if ($child instanceof self) {
                yield from $child->descendants();
            }
        }
    }
}
