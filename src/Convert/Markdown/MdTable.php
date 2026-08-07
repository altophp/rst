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
 * A GFM pipe table: one header row, the per-column alignment from the
 * delimiter row, and zero or more body rows.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MdTable extends MdContainerNode
{
    /**
     * @param list<MdTableAlignment> $alignments
     * @param list<MdTableRow>       $rows
     */
    public function __construct(
        ByteSpan $span,
        public array $alignments,
        public MdTableRow $header,
        private array $rows,
    ) {
        parent::__construct($span);
    }

    /**
     * @return list<MdTableRow>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return list<MdTableRow>
     */
    public function children(): array
    {
        return [$this->header, ...$this->rows];
    }
}
