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
 * Inline literal text, written with double backquotes.
 *
 * The value is verbatim: no escape processing, no whitespace normalization
 * beyond the docutils rule that a line terminator plus the indentation of
 * the next line becomes a single space.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class InlineLiteral extends Node
{
    public function __construct(
        ByteSpan $span,
        public string $text,
    ) {
        if ('' === $text) {
            throw new InvalidArgumentException('Inline literal text must not be empty.');
        }

        parent::__construct($span);
    }
}
