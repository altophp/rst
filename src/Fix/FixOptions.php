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

namespace Alto\Rst\Fix;

use Alto\Rst\Exception\InvalidArgumentException;

/**
 * Selects the mechanically safe source cleanup passes.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FixOptions
{
    public function __construct(
        public bool $removeTrailingWhitespace = true,
        public ?int $maxBlankLines = 2,
        public bool $blankLineAfterAnchor = true,
        public bool $blankLineBeforeDirectiveBody = true,
        public bool $normalizeDefaultRoleAsLiteral = false,
    ) {
        if (null !== $maxBlankLines && $maxBlankLines < 1) {
            throw new InvalidArgumentException(\sprintf('Maximum blank line count must be >= 1 or null, got %d.', $maxBlankLines));
        }
    }
}
