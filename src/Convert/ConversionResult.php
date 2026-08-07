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

use Alto\Rst\Source\ByteSpan;

/**
 * The converted text plus what it cost to produce it.
 *
 * Conversion never throws on constructs it cannot express: it emits the best
 * available output and records the gap in the report.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ConversionResult
{
    /**
     * @param list<ByteSpan> $resolvedReferenceSpans references unresolved by
     *                                               the source-only graph but
     *                                               resolved by conversion
     *                                               context such as includes
     */
    public function __construct(
        public string $output,
        public ConversionReport $report,
        public array $resolvedReferenceSpans = [],
    ) {
    }

    public function status(): ConversionStatus
    {
        return $this->report->status();
    }

    public function isComplete(): bool
    {
        return $this->report->isComplete();
    }

    public function isLossless(): bool
    {
        return $this->report->isLossless();
    }

    public function isExact(): bool
    {
        return $this->report->isExact();
    }
}
