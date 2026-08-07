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
 * A bullet or ordered list.
 *
 * $bulletMarker ("-", "*", or "+") is set for a bullet list; $delimiter and
 * $start are meaningful for an ordered list. $tight is false when any two
 * items are separated by a blank line, or an item directly contains two
 * block-level children separated by a blank line, per CommonMark.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MdList extends MdContainerNode
{
    /**
     * @param list<MdListItem> $items
     */
    public function __construct(
        ByteSpan $span,
        public bool $ordered,
        public ?string $bulletMarker,
        public ?MdListDelimiter $delimiter,
        public int $start,
        public bool $tight,
        private array $items,
    ) {
        parent::__construct($span);
    }

    /**
     * @return list<MdListItem>
     */
    public function children(): array
    {
        return $this->items;
    }
}
