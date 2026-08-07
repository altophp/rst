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
 * The root of the document tree.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Document extends ContainerNode
{
    /**
     * @param list<Node> $children
     */
    public function __construct(
        ByteSpan $span,
        private array $children = [],
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
