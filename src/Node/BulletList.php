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
 * A bullet list. The marker character preserves the original source
 * style; docutils bullets may be multi-byte ("•", "‣", "⁃").
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class BulletList extends ContainerNode
{
    /**
     * @param list<ListItem> $items
     */
    public function __construct(
        ByteSpan $span,
        public string $marker,
        private array $items = [],
    ) {
        if ('' === $marker) {
            throw new InvalidArgumentException('Bullet list marker must not be empty.');
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
