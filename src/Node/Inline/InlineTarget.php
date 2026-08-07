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
 * An inline internal hyperlink target, written `` _`name` ``.
 *
 * The name is whitespace-normalized but not case-folded; docutils name
 * normalization belongs to the reference graph.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class InlineTarget extends Node
{
    public function __construct(
        ByteSpan $span,
        public string $name,
    ) {
        if ('' === $name) {
            throw new InvalidArgumentException('Inline target name must not be empty.');
        }

        parent::__construct($span);
    }
}
