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
 * Deterministic, in-memory result for a complete documentation project.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ProjectConversionResult
{
    public ConversionReport $report;

    /**
     * @param list<ProjectFileConversion> $files
     */
    public function __construct(
        public array $files,
        public ProblemReport $projectReferenceProblems,
    ) {
        $this->report = new ConversionReport(array_merge(...array_map(
            static fn (ProjectFileConversion $file): array => $file->conversion->report->issues,
            $files,
        )));
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

    /**
     * @return list<ProjectFileConversion>
     */
    public function filesWithStatus(ConversionStatus $status): array
    {
        return array_values(array_filter(
            $this->files,
            static fn (ProjectFileConversion $file): bool => $status === $file->status(),
        ));
    }

    /**
     * This queue overlaps with other issue-kind queues by design.
     *
     * @return list<ProjectFileConversion>
     */
    public function filesWithIssueKind(IssueKind $kind): array
    {
        return array_values(array_filter(
            $this->files,
            static fn (ProjectFileConversion $file): bool => $file->conversion->report->hasKind($kind),
        ));
    }

    /**
     * Source parser and effective reference diagnostics, independent from
     * conversion fidelity.
     *
     * @return list<ProjectFileConversion>
     */
    public function filesWithDiagnostics(): array
    {
        return array_values(array_filter(
            $this->files,
            static fn (ProjectFileConversion $file): bool => $file->hasDiagnostics(),
        ));
    }

    public function parseProblems(): ProblemReport
    {
        return new ProblemReport(...array_merge(...array_map(
            static fn (ProjectFileConversion $file): array => $file->parseProblems->problems(),
            $this->files,
        )));
    }

    /**
     * Effective per-file reference diagnostics after conversion-context
     * resolution. Project-wide target problems remain available separately
     * through $projectReferenceProblems.
     */
    public function referenceProblems(): ProblemReport
    {
        return new ProblemReport(...array_merge(...array_map(
            static fn (ProjectFileConversion $file): array => $file->referenceProblems->problems(),
            $this->files,
        )));
    }

    public function file(string $sourcePath): ?ProjectFileConversion
    {
        $sourcePath = self::canonicalLookupPath($sourcePath);

        if (null === $sourcePath) {
            return null;
        }

        foreach ($this->files as $file) {
            if ($sourcePath === $file->sourcePath) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Target paths keyed to their in-memory Markdown output.
     *
     * @return array<string, string>
     */
    public function outputs(): array
    {
        $outputs = [];

        foreach ($this->files as $file) {
            $outputs[$file->targetPath] = $file->conversion->output;
        }

        return $outputs;
    }

    private static function canonicalLookupPath(string $path): ?string
    {
        if (
            str_contains($path, "\0")
            || '' === $path
            || str_starts_with($path, '/')
        ) {
            return null;
        }

        $path = str_replace('\\', '/', $path);

        if (1 === preg_match('/^[A-Za-z]:\//', $path)) {
            return null;
        }

        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }

            if ('..' === $part) {
                if ([] === $parts) {
                    return null;
                }

                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }
}
