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

/**
 * The mutable problem accumulator handed to parsing passes.
 *
 * Passes add problems as they recover from malformed input; the result
 * is exposed as an immutable ProblemReport snapshot.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ProblemCollector implements \Countable
{
    /**
     * @var list<Problem>
     */
    private array $problems = [];

    public function add(Problem $problem): void
    {
        $this->problems[] = $problem;
    }

    public function count(): int
    {
        return count($this->problems);
    }

    public function report(): ProblemReport
    {
        return new ProblemReport(...$this->problems);
    }
}
