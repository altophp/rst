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

namespace Alto\Rst\Tests\Convert\Support;

use Alto\Rst\Node\Node;
use Alto\Rst\Source\ByteSpan;

/**
 * A ROM node type the Markdown writer does not know, used to exercise the
 * unmapped-node fallback.
 */
final readonly class UnmappedNode extends Node
{
    public function __construct(ByteSpan $span)
    {
        parent::__construct($span);
    }
}
