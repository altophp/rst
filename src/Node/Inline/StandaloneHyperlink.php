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
 * A bare URI written in running text, such as `https://example.com/`.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class StandaloneHyperlink extends Node
{
    public function __construct(
        ByteSpan $span,
        public string $uri,
    ) {
        if ('' === $uri) {
            throw new InvalidArgumentException('Standalone hyperlink URI must not be empty.');
        }

        parent::__construct($span);
    }
}
