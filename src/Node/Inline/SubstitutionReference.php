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
 * A substitution reference, written `|name|`.
 *
 * `$reference` is true for the `|name|_` and `|name|__` forms, which also
 * act as hyperlink references. `$anonymous` distinguishes the double
 * underscore form. The node remains the untransformed source shape.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SubstitutionReference extends Node
{
    public function __construct(
        ByteSpan $span,
        public string $name,
        public bool $reference = false,
        public bool $anonymous = false,
    ) {
        if ('' === $name) {
            throw new InvalidArgumentException('Substitution reference name must not be empty.');
        }

        parent::__construct($span);
    }
}
