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

namespace Alto\Rst\Reference;

use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemReport;
use Alto\Rst\Problem\ProblemSeverity;

/**
 * Two-pass project resolution without filesystem access.
 *
 * Callers provide already parsed documents keyed by project-relative path.
 * Input order has no effect on lookup or query results.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ProjectReferenceMap
{
    /**
     * @var array<string, ReferenceGraph>
     */
    private array $documents = [];

    /**
     * @var array<string, string>
     */
    private array $aliases = [];

    /**
     * @var array<string, list<array{string, ReferenceDefinition}>>
     */
    private array $labels = [];

    /**
     * @var array<string, list<array{string, ReferenceDefinition}>>
     */
    private array $sectionLabels = [];

    /**
     * @var array<string, ProjectReferenceResolution>
     */
    private array $resolutions = [];

    /**
     * @var array<string, list<ProjectReferenceResolution>>
     */
    private array $outgoing = [];

    /**
     * @var array<string, list<ProjectReferenceResolution>>
     */
    private array $incoming = [];

    private ProblemReport $problems;

    /**
     * @param array<string, ReferenceGraph> $documents
     */
    public function __construct(
        array $documents,
        private readonly bool $implicitSectionLabels = false,
    ) {
        ksort($documents);

        foreach ($documents as $path => $graph) {
            $canonical = self::canonicalPath($path);
            $this->documents[$canonical] = $graph;
            $this->aliases[$canonical] = $canonical;
        }

        foreach (array_keys($this->documents) as $canonical) {
            if (str_ends_with($canonical, '/index')) {
                $alias = substr($canonical, 0, -6);
                $this->aliases[$alias] ??= $canonical;
            }
        }

        foreach ($this->documents as $path => $graph) {
            foreach ($graph->explicitDefinitions() as $name => $definitions) {
                if (\count($definitions) > 1) {
                    foreach ($definitions as $definition) {
                        $this->labels[$name][] = [$path, $definition];
                    }

                    continue;
                }

                $target = $graph->explicitTarget($name);

                if (null !== $target) {
                    $this->labels[$name][] = [$path, $target];
                }
            }

            if ($this->implicitSectionLabels) {
                foreach ($graph->definitions(DefinitionKind::Section) as $definition) {
                    $this->sectionLabels[ReferenceName::id($definition->name)][] = [$path, $definition];
                }
            }
        }

        $collector = new ProblemCollector();

        foreach ($this->documents as $path => $graph) {
            foreach ($graph->references() as $reference) {
                if (!\in_array($reference->type, [ReferenceType::SphinxRef, ReferenceType::SphinxDoc], true)) {
                    continue;
                }

                $resolution = $this->resolveProjectReference($path, $reference);
                $key = self::resolutionKey($path, $reference);
                $this->resolutions[$key] = $resolution;
                $this->outgoing[$path][] = $resolution;

                if (null !== $resolution->targetPath) {
                    $this->incoming[$resolution->targetPath][] = $resolution;
                }

                if (\in_array($resolution->status, [ReferenceStatus::Unresolved, ReferenceStatus::Ambiguous], true)) {
                    $collector->add(new Problem(
                        ProblemSeverity::Error,
                        ReferenceStatus::Ambiguous === $resolution->status
                            ? 'reference/ambiguous-project-target'
                            : 'reference/unresolved-project-target',
                        \sprintf(
                            'Project reference "%s" from "%s" is %s.',
                            $reference->label,
                            $path,
                            $resolution->status->value,
                        ),
                        $reference->span,
                    ));
                }
            }
        }

        $this->problems = $collector->report();
    }

    public function resolution(string $sourcePath, ReferenceOccurrence $reference): ?ProjectReferenceResolution
    {
        return $this->resolutions[self::resolutionKey(self::canonicalPath($sourcePath), $reference)] ?? null;
    }

    /**
     * Resolves a project reference by meaning rather than indexed source span.
     *
     * This is useful for consumers that re-parse a source fragment, where the
     * occurrence has fragment-local offsets but still belongs to a catalogued
     * source document.
     */
    public function resolveReference(
        string $sourcePath,
        ReferenceOccurrence $reference,
    ): ?ProjectReferenceResolution {
        $sourcePath = self::canonicalPath($sourcePath);

        if (
            !isset($this->documents[$sourcePath])
            || !\in_array($reference->type, [ReferenceType::SphinxRef, ReferenceType::SphinxDoc], true)
        ) {
            return null;
        }

        return $this->resolveProjectReference($sourcePath, $reference);
    }

    /**
     * @return list<ProjectReferenceResolution>
     */
    public function outgoing(string $sourcePath): array
    {
        return $this->outgoing[self::canonicalPath($sourcePath)] ?? [];
    }

    /**
     * @return list<ProjectReferenceResolution>
     */
    public function incoming(string $targetPath): array
    {
        $canonical = self::canonicalPath($targetPath);
        $canonical = $this->aliases[$canonical] ?? $canonical;

        return $this->incoming[$canonical] ?? [];
    }

    public function problems(): ProblemReport
    {
        return $this->problems;
    }

    /**
     * @return list<string>
     */
    public function documentPaths(): array
    {
        return array_keys($this->documents);
    }

    public function documentTitleFor(string $path): ?string
    {
        $canonical = self::canonicalPath($path);
        $canonical = $this->aliases[$canonical] ?? $canonical;

        return isset($this->documents[$canonical])
            ? $this->documentTitle($this->documents[$canonical])
            : null;
    }

    public function resolveDocumentPath(string $path): ?string
    {
        $canonical = self::canonicalPath($path);
        $canonical = $this->aliases[$canonical] ?? $canonical;

        return isset($this->documents[$canonical]) ? $canonical : null;
    }

    private function resolveProjectReference(
        string $sourcePath,
        ReferenceOccurrence $reference,
    ): ProjectReferenceResolution {
        if (ReferenceType::SphinxDoc === $reference->type) {
            return $this->resolveDocument($sourcePath, $reference);
        }

        return $this->resolveLabel($sourcePath, $reference);
    }

    private function resolveDocument(
        string $sourcePath,
        ReferenceOccurrence $reference,
    ): ProjectReferenceResolution {
        $label = trim($reference->label);

        if (str_starts_with($label, '/')) {
            $candidate = self::canonicalPath($label);
        } else {
            $directory = str_contains($sourcePath, '/')
                ? substr($sourcePath, 0, (int) strrpos($sourcePath, '/'))
                : '';
            $candidate = self::canonicalPath(('' === $directory ? '' : $directory.'/').$label);
        }

        $targetPath = $this->aliases[$candidate] ?? null;

        if (null === $targetPath || !isset($this->documents[$targetPath])) {
            return new ProjectReferenceResolution(
                $sourcePath,
                $reference,
                ReferenceStatus::Unresolved,
                null,
                null,
                $reference->explicitTitle,
            );
        }

        $title = $reference->explicitTitle ?? $this->documentTitle($this->documents[$targetPath]) ?? basename($targetPath);

        return new ProjectReferenceResolution(
            $sourcePath,
            $reference,
            ReferenceStatus::Resolved,
            $targetPath,
            null,
            $title,
        );
    }

    private function resolveLabel(
        string $sourcePath,
        ReferenceOccurrence $reference,
    ): ProjectReferenceResolution {
        $matches = $this->labels[ReferenceName::normalize($reference->label)] ?? [];

        if ([] !== $matches) {
            return $this->resolutionFromMatches($sourcePath, $reference, $matches);
        }

        if (!$this->implicitSectionLabels) {
            return new ProjectReferenceResolution(
                $sourcePath,
                $reference,
                ReferenceStatus::Unresolved,
                null,
                null,
                $reference->explicitTitle,
            );
        }

        $documentPath = self::canonicalPath($reference->label);
        $documentPath = $this->aliases[$documentPath] ?? $documentPath;

        if (isset($this->documents[$documentPath])) {
            $sections = $this->documents[$documentPath]->definitions(DefinitionKind::Section);
            $target = $sections[0] ?? null;

            return new ProjectReferenceResolution(
                $sourcePath,
                $reference,
                ReferenceStatus::Resolved,
                $documentPath,
                $target,
                $reference->explicitTitle
                    ?? (null === $target ? basename($documentPath) : $this->documents[$documentPath]->targetTitle($target)),
            );
        }

        $matches = $this->sectionLabels[ReferenceName::id($reference->label)] ?? [];
        $localMatches = array_values(array_filter(
            $matches,
            static fn (array $match): bool => $sourcePath === $match[0],
        ));

        return $this->resolutionFromMatches(
            $sourcePath,
            $reference,
            [] === $localMatches ? $matches : $localMatches,
        );
    }

    /**
     * @param list<array{string, ReferenceDefinition}> $matches
     */
    private function resolutionFromMatches(
        string $sourcePath,
        ReferenceOccurrence $reference,
        array $matches,
    ): ProjectReferenceResolution {
        if (1 !== \count($matches)) {
            return new ProjectReferenceResolution(
                $sourcePath,
                $reference,
                [] === $matches ? ReferenceStatus::Unresolved : ReferenceStatus::Ambiguous,
                null,
                null,
                $reference->explicitTitle,
            );
        }

        [$targetPath, $target] = $matches[0];

        return new ProjectReferenceResolution(
            $sourcePath,
            $reference,
            ReferenceStatus::Resolved,
            $targetPath,
            $target,
            $reference->explicitTitle ?? $this->documents[$targetPath]->targetTitle($target),
        );
    }

    private function documentTitle(ReferenceGraph $graph): ?string
    {
        $sections = $graph->definitions(DefinitionKind::Section);

        return $sections[0]->name ?? null;
    }

    private static function canonicalPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        if (str_ends_with($path, '.rst')) {
            $path = substr($path, 0, -4);
        }

        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }

            if ('..' === $part) {
                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private static function resolutionKey(string $sourcePath, ReferenceOccurrence $reference): string
    {
        return $sourcePath.':'.ReferenceGraph::spanKey($reference->span);
    }
}
