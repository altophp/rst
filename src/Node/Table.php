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
 * A table. Head rows and body rows are kept apart because the source
 * separates them: a simple table closes its head with a second border.
 *
 * $columnWidths is the width of each column as the source drew it, so the
 * formatter can redraw the borders and the translator can keep the
 * original alignment. It is not the width of the widest cell: the last
 * column of a simple table is unbounded and its content may overflow the
 * border it was written under.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Table extends ContainerNode
{
    /**
     * @param list<TableRow> $head
     * @param list<TableRow> $body
     * @param list<int>      $columnWidths
     */
    public function __construct(
        ByteSpan $span,
        public array $head,
        public array $body,
        public array $columnWidths,
        public TableStyle $style,
    ) {
        foreach ($columnWidths as $width) {
            if ($width < 1) {
                throw new InvalidArgumentException(\sprintf('Table column width must be >= 1, got %d.', $width));
            }
        }

        parent::__construct($span);
    }

    /**
     * @return list<TableRow>
     */
    public function children(): array
    {
        return [...$this->head, ...$this->body];
    }
}
