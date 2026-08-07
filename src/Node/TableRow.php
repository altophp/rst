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

use Alto\Rst\Source\ByteSpan;

/**
 * One row of a table. A row holds fewer cells than the table has columns
 * when one of its cells spans several columns.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class TableRow extends ContainerNode
{
    /**
     * @param list<TableCell> $cells
     */
    public function __construct(
        ByteSpan $span,
        private array $cells = [],
    ) {
        parent::__construct($span);
    }

    /**
     * @return list<TableCell>
     */
    public function children(): array
    {
        return $this->cells;
    }
}
