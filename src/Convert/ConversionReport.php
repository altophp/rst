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

/**
 * What a conversion could not carry over, and where.
 *
 * The Swift prototype that preceded this engine proved the metric that
 * matters is not "it converted" but "which constructs were lost and how
 * often". Counting by kind and construct turns a conversion run into a work
 * list.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ConversionReport
{
    /**
     * @param list<ConversionIssue> $issues
     */
    public function __construct(
        public array $issues = [],
    ) {}

    public function isEmpty(): bool
    {
        return [] === $this->issues;
    }

    public function status(): ConversionStatus
    {
        if ($this->hasKind(IssueKind::Unsupported)) {
            return ConversionStatus::Blocked;
        }
        if ($this->hasKind(IssueKind::Lossy)) {
            return ConversionStatus::Review;
        }
        if ($this->hasKind(IssueKind::Approximated)) {
            return ConversionStatus::Tracked;
        }

        return ConversionStatus::Exact;
    }

    /**
     * True when every source construct produced a supported target equivalent.
     */
    public function isComplete(): bool
    {
        return !$this->hasKind(IssueKind::Unsupported);
    }

    /**
     * True when no source information is known to have been discarded.
     */
    public function isLossless(): bool
    {
        return !$this->hasKind(IssueKind::Unsupported)
            && !$this->hasKind(IssueKind::Lossy);
    }

    /**
     * True when no unsupported, lossy, or approximated mapping was recorded.
     */
    public function isExact(): bool
    {
        return [] === $this->issues;
    }

    public function hasKind(IssueKind $kind): bool
    {
        foreach ($this->issues as $issue) {
            if ($kind === $issue->kind) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ConversionIssue>
     */
    public function ofKind(IssueKind $kind): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn(ConversionIssue $issue): bool => $issue->kind === $kind,
        ));
    }

    /**
     * Issue counts per construct key, ordered by descending count then by key.
     *
     * @return array<string, int>
     */
    public function countsByConstruct(): array
    {
        $counts = [];

        foreach ($this->issues as $issue) {
            $counts[$issue->construct] = ($counts[$issue->construct] ?? 0) + 1;
        }

        uksort($counts, static function (string $a, string $b) use ($counts): int {
            return [$counts[$b], $a] <=> [$counts[$a], $b];
        });

        return $counts;
    }

    /**
     * @return array{unsupported: int, lossy: int, approximated: int}
     */
    public function countsByKind(): array
    {
        return [
            IssueKind::Unsupported->value => \count($this->ofKind(IssueKind::Unsupported)),
            IssueKind::Lossy->value => \count($this->ofKind(IssueKind::Lossy)),
            IssueKind::Approximated->value => \count($this->ofKind(IssueKind::Approximated)),
        ];
    }

    /**
     * @return array{
     *     unsupported: array<string, int>,
     *     lossy: array<string, int>,
     *     approximated: array<string, int>
     * }
     */
    public function countsByKindAndConstruct(): array
    {
        return [
            IssueKind::Unsupported->value => $this->constructCountsForKind(IssueKind::Unsupported),
            IssueKind::Lossy->value => $this->constructCountsForKind(IssueKind::Lossy),
            IssueKind::Approximated->value => $this->constructCountsForKind(IssueKind::Approximated),
        ];
    }

    public function merge(self $other): self
    {
        return new self([...$this->issues, ...$other->issues]);
    }

    /**
     * @return array<string, int>
     */
    private function constructCountsForKind(IssueKind $kind): array
    {
        $counts = [];

        foreach ($this->issues as $issue) {
            if ($kind !== $issue->kind) {
                continue;
            }

            $counts[$issue->construct] = ($counts[$issue->construct] ?? 0) + 1;
        }

        uksort($counts, static function (string $a, string $b) use ($counts): int {
            return [$counts[$b], $a] <=> [$counts[$a], $b];
        });

        return $counts;
    }
}
