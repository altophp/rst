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
 * Base type of every Markdown model node.
 *
 * Deliberately independent of Alto\Rst\Node\Node: the Markdown model is a
 * separate reader-side tree, not a variant of the reStructuredText ROM.
 * Every node carries a span over the original Markdown input bytes.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
abstract readonly class MdNode
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
