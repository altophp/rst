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

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Node\Node;
use Alto\Rst\Source\ByteSpan;

/**
 * A citation reference, written `[CIT2002]_`.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CitationReference extends Node
{
    public function __construct(
        ByteSpan $span,
        public string $label,
    ) {
        if ('' === $label) {
            throw new InvalidArgumentException('Citation reference label must not be empty.');
        }

        parent::__construct($span);
    }
}
