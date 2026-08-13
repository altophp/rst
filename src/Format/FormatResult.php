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

use Alto\Rst\Operation\SourcePatch;

/**
 * The formatted bytes and the exact edits made against the original source.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FormatResult
{
    /**
     * @param list<SourcePatch> $patches
     */
    public function __construct(
        public string $bytes,
        public array $patches,
        public int $skippedSectionTitles = 0,
        public int $skippedBulletLists = 0,
        public int $skippedExtensionPasses = 0,
    ) {}

    public function changed(): bool
    {
        return [] !== $this->patches;
    }
}
