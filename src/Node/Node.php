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
 * Base type of every ROM node.
 *
 * Every node carries a span over the original input bytes. The tree is
 * immutable: the editing layer arrives in a later phase and decides its
 * own mutation strategy.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
abstract readonly class Node
{
    protected function __construct(
        private ByteSpan $span,
    ) {
    }

    final public function span(): ByteSpan
    {
        return $this->span;
    }
}
