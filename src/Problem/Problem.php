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

namespace Alto\Rst\Problem;

use Alto\Rst\Source\ByteSpan;

/**
 * A reported parsing, linting, or rendering problem.
 *
 * Problems are the recovery channel: malformed input degrades to problems
 * with source positions, never to exceptions. The code is a stable
 * kebab-case identifier grouped by area, such as "section/short-adornment".
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Problem
{
    public function __construct(
        public ProblemSeverity $severity,
        public string $code,
        public string $message,
        public ?ByteSpan $span = null,
    ) {
    }
}
