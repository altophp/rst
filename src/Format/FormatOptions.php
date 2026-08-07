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

namespace Alto\Rst\Format;

use Alto\Rst\Exception\InvalidArgumentException;

/**
 * Selects the conservative, source-preserving formatting passes.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FormatOptions
{
    public function __construct(
        public bool $normalizeSectionAdornments = true,
        public ?string $bulletMarker = '-',
        public ?int $lineWidth = null,
        public bool $alignSimpleTables = true,
    ) {
        if (null !== $bulletMarker && !\in_array($bulletMarker, ['-', '*', '+'], true)) {
            throw new InvalidArgumentException(\sprintf('Bullet marker must be "-", "*", "+", or null, got "%s".', $bulletMarker));
        }

        if (null !== $lineWidth && $lineWidth < 1) {
            throw new InvalidArgumentException(\sprintf('Line width must be >= 1 or null, got %d.', $lineWidth));
        }
    }
}
