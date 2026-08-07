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

namespace Alto\Rst\Node;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Source\ByteSpan;

/**
 * One cell of a table row. Children are block nodes: a one-line cell holds
 * a single Paragraph, a multi-line cell may hold any block structure.
 *
 * The spans are counts, not the docutils "morecols" and "morerows"
 * offsets: a cell joining two columns has a colspan of 2.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class TableCell extends ContainerNode
{
    /**
     * @param list<Node> $children
     */
    public function __construct(
        ByteSpan $span,
        private array $children,
        public int $colspan = 1,
        public int $rowspan = 1,
    ) {
        if ($colspan < 1) {
            throw new InvalidArgumentException(\sprintf('Table cell colspan must be >= 1, got %d.', $colspan));
        }

        if ($rowspan < 1) {
            throw new InvalidArgumentException(\sprintf('Table cell rowspan must be >= 1, got %d.', $rowspan));
        }

        parent::__construct($span);
    }

    /**
     * @return list<Node>
     */
    public function children(): array
    {
        return $this->children;
    }
}
