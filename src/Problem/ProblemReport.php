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
 * An immutable, ordered collection of reported problems.
 *
 * @implements \IteratorAggregate<int, Problem>
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ProblemReport implements \Countable, \IteratorAggregate
{
    /**
     * @var list<Problem>
     */
    private array $problems;

    public function __construct(Problem ...$problems)
    {
        $this->problems = array_values($problems);
    }

    /**
     * @return list<Problem>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * @return \ArrayIterator<int, Problem>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->problems);
    }

    public function count(): int
    {
        return count($this->problems);
    }

    public function hasProblems(): bool
    {
        return [] !== $this->problems;
    }

    public function maxSeverity(): ?ProblemSeverity
    {
        $max = null;

        foreach ($this->problems as $problem) {
            if (null === $max || $problem->severity->isAtLeast($max)) {
                $max = $problem->severity;
            }
        }

        return $max;
    }

    public function hasAtLeast(ProblemSeverity $severity): bool
    {
        return true === $this->maxSeverity()?->isAtLeast($severity);
    }

    public function filterBySeverity(ProblemSeverity $minimum): self
    {
        return new self(...array_filter(
            $this->problems,
            static fn(Problem $problem): bool => $problem->severity->isAtLeast($minimum),
        ));
    }

    /**
     * Keeps problems whose code area (the part before the slash) equals
     * the given area; slashless codes match on the whole code.
     */
    public function filterByArea(string $area): self
    {
        return new self(...array_filter(
            $this->problems,
            static fn(Problem $problem): bool => $area === self::area($problem->code),
        ));
    }

    /**
     * @return array{severe: int, error: int, warning: int, info: int}
     */
    public function countsBySeverity(): array
    {
        $counts = [
            ProblemSeverity::Severe->value => 0,
            ProblemSeverity::Error->value => 0,
            ProblemSeverity::Warning->value => 0,
            ProblemSeverity::Info->value => 0,
        ];

        foreach ($this->problems as $problem) {
            ++$counts[$problem->severity->value];
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function countsByCode(): array
    {
        $counts = [];

        foreach ($this->problems as $problem) {
            $counts[$problem->code] = ($counts[$problem->code] ?? 0) + 1;
        }

        uksort($counts, static function (string $a, string $b) use ($counts): int {
            return [$counts[$b], $a] <=> [$counts[$a], $b];
        });

        return $counts;
    }

    public function merge(self $other): self
    {
        return new self(...$this->problems, ...$other->problems);
    }

    private static function area(string $code): string
    {
        $slash = strpos($code, '/');

        return false === $slash ? $code : substr($code, 0, $slash);
    }
}
