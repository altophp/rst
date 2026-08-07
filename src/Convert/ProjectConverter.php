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

use Alto\Rst\Exception\FileReadException;
use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Parser\ParseResult;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemReport;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Reference\ProjectReferenceMap;
use Alto\Rst\Source\Source;

/**
 * Converts a complete RST project in two passes without writing files.
 *
 * The first pass parses every source and builds one project reference map.
 * The second pass converts each document with that map. RST directives do
 * not influence discovery, so include and other file-reading directives
 * remain inert.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ProjectConverter
{
    public function convertDirectory(
        string $root,
        ?Profile $profile = null,
        ?ConversionOptions $options = null,
    ): ProjectConversionResult {
        return $this->convertCanonicalSources(
            $this->canonicalSources($this->readDirectory($root), false),
            $profile,
            $options,
        );
    }

    /**
     * Converts caller-supplied sources keyed by project-relative RST path.
     * Forward slashes and backslashes are accepted as portable separators.
     * Path components are never trimmed.
     *
     * @param array<string, string> $sources
     */
    public function convertSources(
        array $sources,
        ?Profile $profile = null,
        ?ConversionOptions $options = null,
    ): ProjectConversionResult {
        return $this->convertCanonicalSources(
            $this->canonicalSources($sources, true),
            $profile,
            $options,
        );
    }

    /**
     * @param array<string, string> $sources
     */
    private function convertCanonicalSources(
        array $sources,
        ?Profile $profile,
        ?ConversionOptions $options,
    ): ProjectConversionResult {
        $effectiveProfile = $profile ?? Profile::docutils();
        $effectiveOptions = $options ?? new ConversionOptions();
        $parser = new BlockParser();

        /**
         * @var array<string, array{Source, ParseResult}>
         */
        $parsed = [];
        $graphs = [];

        foreach ($sources as $path => $bytes) {
            $source = Source::fromString($bytes);
            $result = $parser->parse($source, $effectiveProfile);
            $parsed[$path] = [$source, $result];
            $graphs[$path] = $result->references();
        }

        $projectReferences = new ProjectReferenceMap(
            $graphs,
            $effectiveOptions->implicitSectionReferences,
        );
        $converter = new RstToMarkdown();
        $files = [];

        foreach ($parsed as $path => [$source, $result]) {
            $conversion = $converter->convert(
                $result->document(),
                $source,
                $effectiveProfile,
                $effectiveOptions,
                $result->references(),
                $projectReferences,
                $path,
            );
            $sourceReferenceProblems = $result->references()->problems();
            $files[] = new ProjectFileConversion(
                $path,
                self::targetPath($path),
                $conversion,
                $result->problems(),
                self::effectiveReferenceProblems($sourceReferenceProblems, $conversion->resolvedReferenceSpans),
                $sourceReferenceProblems,
            );
        }

        return new ProjectConversionResult(
            $files,
            $projectReferences->problems(),
        );
    }

    /**
     * Removes source-only unresolved diagnostics when conversion context
     * proved that an explicitly authorized include supplies the target.
     *
     * @param list<\Alto\Rst\Source\ByteSpan> $resolvedSpans
     */
    private static function effectiveReferenceProblems(
        ProblemReport $problems,
        array $resolvedSpans,
    ): ProblemReport {
        $resolved = [];

        foreach ($resolvedSpans as $span) {
            $resolved[$span->start.':'.$span->length] = true;
        }

        return new ProblemReport(...array_values(array_filter(
            $problems->problems(),
            static function (Problem $problem) use ($resolved): bool {
                if ('reference/unresolved-target' !== $problem->code || null === $problem->span) {
                    return true;
                }

                return !isset($resolved[$problem->span->start.':'.$problem->span->length]);
            },
        )));
    }

    /**
     * @return array<string, string>
     */
    private function readDirectory(string $root): array
    {
        if ('' === trim($root)) {
            throw new InvalidArgumentException('Project root must not be empty.');
        }

        $realRoot = realpath($root);

        if (false === $realRoot || !is_dir($realRoot) || !is_readable($realRoot)) {
            throw new InvalidArgumentException(\sprintf('Project root "%s" is not a readable directory.', $root));
        }

        $sources = [];

        try {
            $directory = new \RecursiveDirectoryIterator(
                $realRoot,
                \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::SKIP_DOTS,
            );
            $iterator = new \RecursiveIteratorIterator($directory, \RecursiveIteratorIterator::LEAVES_ONLY);

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->isLink() || !$file->isFile()) {
                    continue;
                }

                $absolutePath = $file->getRealPath();

                if (false === $absolutePath || !str_ends_with($absolutePath, '.rst')) {
                    continue;
                }

                $relativePath = str_replace(\DIRECTORY_SEPARATOR, '/', substr($absolutePath, \strlen($realRoot) + 1));
                $bytes = @file_get_contents($absolutePath);

                if (false === $bytes) {
                    throw new FileReadException(\sprintf('Unable to read RST source "%s".', $relativePath));
                }

                $sources[$relativePath] = $bytes;
            }
        } catch (\UnexpectedValueException $exception) {
            throw new FileReadException(\sprintf('Unable to traverse project root "%s".', $root), previous: $exception);
        }

        ksort($sources, \SORT_STRING);

        return $sources;
    }

    /**
     * @param array<string, string> $sources
     * @param bool                  $portableSeparators when true, both slash
     *                                                  styles are logical path
     *                                                  separators
     *
     * @return array<string, string>
     */
    private function canonicalSources(array $sources, bool $portableSeparators): array
    {
        $canonical = [];

        foreach ($sources as $path => $bytes) {
            $normalized = self::canonicalSourcePath($path, $portableSeparators);

            if (isset($canonical[$normalized])) {
                throw new InvalidArgumentException(\sprintf('Duplicate project source path "%s".', $normalized));
            }

            $canonical[$normalized] = $bytes;
        }

        ksort($canonical, \SORT_STRING);

        return $canonical;
    }

    private static function canonicalSourcePath(string $path, bool $portableSeparators): string
    {
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Project source path must not contain null bytes.');
        }

        if ($portableSeparators) {
            $path = str_replace('\\', '/', $path);
        }

        if (
            '' === $path
            || str_starts_with($path, '/')
            || ($portableSeparators && 1 === preg_match('/^[A-Za-z]:\//', $path))
        ) {
            throw new InvalidArgumentException(\sprintf('Project source path "%s" must be relative.', $path));
        }

        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }

            if ('..' === $part) {
                throw new InvalidArgumentException(\sprintf('Project source path "%s" must not traverse its root.', $path));
            }

            $parts[] = $part;
        }

        $canonical = implode('/', $parts);

        if (!str_ends_with($canonical, '.rst')) {
            throw new InvalidArgumentException(\sprintf('Project source path "%s" must end in .rst.', $path));
        }

        return $canonical;
    }

    private static function targetPath(string $sourcePath): string
    {
        return substr($sourcePath, 0, -4).'.md';
    }
}
