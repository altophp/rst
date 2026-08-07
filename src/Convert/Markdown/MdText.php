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
 * A run of literal text.
 *
 * Backslash escapes are already resolved; a soft or hard line break inside
 * the source collapses to a single space, matching how the rest of this
 * engine normalizes inline whitespace.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MdText extends MdNode
{
    public function __construct(
        ByteSpan $span,
        public string $text,
    ) {
        parent::__construct($span);
    }
}
