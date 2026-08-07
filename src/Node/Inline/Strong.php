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

namespace Alto\Rst\Node\Inline;

use Alto\Rst\Node\ContainerNode;
use Alto\Rst\Node\Node;
use Alto\Rst\Source\ByteSpan;

/**
 * Strongly emphasized text, written `**text**`.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Strong extends ContainerNode
{
    /**
     * @param list<Node> $children
     */
    public function __construct(
        ByteSpan $span,
        public array $children = [],
    ) {
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
