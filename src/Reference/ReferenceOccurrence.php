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

namespace Alto\Rst\Reference;

use Alto\Rst\Source\ByteSpan;

/**
 * One reference as written, with its document-wide resolution.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ReferenceOccurrence
{
    public function __construct(
        public ReferenceType $type,
        public string $label,
        public string $normalizedLabel,
        public ByteSpan $span,
        public ?string $explicitTitle,
        public ReferenceStatus $status,
        public ?ReferenceDefinition $target,
        public ?string $displayLabel = null,
    ) {}
}
