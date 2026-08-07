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

use Alto\Rst\Source\ByteSpan;

/**
 * One row of an MdTable: the header row or one body row.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MdTableRow extends MdContainerNode
{
    /**
     * @param list<MdTableCell> $cells
     */
    public function __construct(
        ByteSpan $span,
        private array $cells,
    ) {
        parent::__construct($span);
    }

    /**
     * @return list<MdTableCell>
     */
    public function children(): array
    {
        return $this->cells;
    }
}
