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

/**
 * A cross-document resolution over caller-supplied parsed documents.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ProjectReferenceResolution
{
    public function __construct(
        public string $sourcePath,
        public ReferenceOccurrence $reference,
        public ReferenceStatus $status,
        public ?string $targetPath,
        public ?ReferenceDefinition $target,
        public ?string $displayLabel,
    ) {
    }
}
