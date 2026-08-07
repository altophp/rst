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
 * A definition list containing terms and their block-level definitions.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DefinitionList extends ContainerNode
{
    /**
     * @param list<DefinitionListItem> $items
     */
    public function __construct(
        ByteSpan $span,
        private array $items = [],
    ) {
        parent::__construct($span);
    }

    /**
     * @return list<DefinitionListItem>
     */
    public function children(): array
    {
        return $this->items;
    }
}
