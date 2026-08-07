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

namespace Alto\Rst\Convert;

use Alto\Rst\Problem\ProblemReport;

/**
 * One in-memory result from a project conversion.
 *
 * Paths are canonical and relative to the converted project root. The
 * driver never writes the output to disk.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ProjectFileConversion
{
    public ProblemReport $sourceReferenceProblems;

    public function __construct(
        public string $sourcePath,
        public string $targetPath,
        public ConversionResult $conversion,
        public ProblemReport $parseProblems,
        public ProblemReport $referenceProblems,
        ?ProblemReport $sourceReferenceProblems = null,
    ) {
        $this->sourceReferenceProblems = $sourceReferenceProblems ?? $referenceProblems;
    }

    public function status(): ConversionStatus
    {
        return $this->conversion->status();
    }

    public function isComplete(): bool
    {
        return $this->conversion->isComplete();
    }

    public function isLossless(): bool
    {
        return $this->conversion->isLossless();
    }

    public function isExact(): bool
    {
        return $this->conversion->isExact();
    }

    public function hasDiagnostics(): bool
    {
        return $this->parseProblems->hasProblems()
            || $this->referenceProblems->hasProblems();
    }
}
