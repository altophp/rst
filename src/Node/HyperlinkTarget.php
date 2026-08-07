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

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Source\ByteSpan;

/**
 * An explicit hyperlink target. Internal targets carry an empty target
 * string; anonymous targets carry an empty name.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HyperlinkTarget extends Node
{
    public function __construct(
        ByteSpan $span,
        public string $name,
        public string $target,
        public bool $anonymous = false,
    ) {
        if ($anonymous && '' !== $name) {
            throw new InvalidArgumentException('Anonymous hyperlink targets carry no name.');
        }

        if (!$anonymous && '' === $name) {
            throw new InvalidArgumentException('Named hyperlink targets require a name.');
        }

        parent::__construct($span);
    }
}
