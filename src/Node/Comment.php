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
 * A comment. The text is the raw comment content, marker excluded.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Comment extends Node
{
    public function __construct(
        ByteSpan $span,
        public string $text = '',
    ) {
        parent::__construct($span);
    }
}
