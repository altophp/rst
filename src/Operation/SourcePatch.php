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

namespace Alto\Rst\Operation;

use Alto\Rst\Source\ByteSpan;

/**
 * One byte-accurate replacement over the original source.
 *
 * An empty span inserts bytes. An empty replacement deletes the span.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SourcePatch
{
    public function __construct(
        public ByteSpan $span,
        public string $replacement,
    ) {
    }
}
