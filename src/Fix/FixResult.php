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

use Alto\Rst\Operation\SourcePatch;
use Alto\Rst\Parser\ParseResult;

/**
 * The fixed bytes, exact source edits, and parse of the resulting document.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FixResult
{
    /**
     * @param list<SourcePatch> $patches
     */
    public function __construct(
        public string $bytes,
        public array $patches,
        public ParseResult $parseResult,
        public int $skippedProtectedEdits = 0,
    ) {}

    public function changed(): bool
    {
        return [] !== $this->patches;
    }
}
