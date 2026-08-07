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

namespace Alto\Rst\Lint\Rule;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Lint\SourceRule;
use Alto\Rst\Node\Document;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Line;
use Alto\Rst\Source\Source;

/**
 * Checks that no run of consecutive blank lines exceeds the configured
 * maximum, reported once per run.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MaxBlankLinesRule implements SourceRule
{
    public function __construct(
        private int $max = 2,
    ) {
        if ($max < 1) {
            throw new InvalidArgumentException(sprintf('Maximum blank line count must be >= 1, got %d.', $max));
        }
    }

    public function code(): string
    {
        return 'lint/max-blank-lines';
    }

    public function check(Document $document, Source $source, ProblemCollector $problems): void
    {
        /** @var list<Line> $run */
        $run = [];

        foreach ($source->lines() as $line) {
            if ($line->isBlank()) {
                $run[] = $line;

                continue;
            }

            $this->flush($run, $problems);
            $run = [];
        }

        $this->flush($run, $problems);
    }

    /**
     * @param list<Line> $run
     */
    private function flush(array $run, ProblemCollector $problems): void
    {
        if (\count($run) <= $this->max) {
            return;
        }

        $first = $run[0];
        $last = $run[\count($run) - 1];

        $problems->add(new Problem(
            ProblemSeverity::Info,
            $this->code(),
            sprintf('%d consecutive blank lines exceed the maximum of %d.', \count($run), $this->max),
            ByteSpan::between($first->span->start, $last->span->end()),
        ));
    }
}
