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
 * A footnote reference, written `[1]_`, `[#]_`, `[#name]_`, or `[*]_`.
 *
 * The label is the raw source label: auto-numbering and auto-symbols stay
 * unresolved until a later pass asks for them.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FootnoteReference extends Node
{
    public function __construct(
        ByteSpan $span,
        public string $label,
    ) {
        if ('' === $label) {
            throw new InvalidArgumentException('Footnote reference label must not be empty.');
        }

        parent::__construct($span);
    }
}
