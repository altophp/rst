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

use Alto\Rst\Node\Node;
use Alto\Rst\Source\ByteSpan;

/**
 * A run of plain text between inline constructs.
 *
 * The value is whitespace-normalized: every whitespace run, including a
 * line terminator plus the indentation of the next line, collapses to a
 * single space. The span still covers the raw source bytes.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class InlineText extends Node
{
    public function __construct(
        ByteSpan $span,
        public string $text,
    ) {
        parent::__construct($span);
    }
}
