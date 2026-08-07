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
 * An enumerated list with a resolved numbering style and start value.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class EnumeratedList extends ContainerNode
{
    /**
     * @param list<ListItem> $items
     */
    public function __construct(
        ByteSpan $span,
        public EnumerationStyle $style,
        public int $start,
        private array $items = [],
    ) {
        if ($start < 0) {
            throw new InvalidArgumentException(sprintf('Enumerated list start must be >= 0, got %d.', $start));
        }

        parent::__construct($span);
    }

    /**
     * @return list<ListItem>
     */
    public function children(): array
    {
        return $this->items;
    }
}
