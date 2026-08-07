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

namespace Alto\Rst\Security;

use Alto\Rst\Exception\FileReadException;
use Alto\Rst\Exception\InvalidArgumentException;

/**
 * Explicit, root-confined access for file-reading directives.
 *
 * Paths beginning with "/" are relative to the configured root. Other
 * paths resolve beside the including source. Realpath checks prevent both
 * lexical traversal and symlink escapes.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FileAccessPolicy
{
    private function __construct(
        public string $root,
        public int $maxIncludeDepth,
    ) {
    }

    public static function rootedAt(string $root, int $maxIncludeDepth = 32): self
    {
        $resolved = realpath($root);

        if (false === $resolved || !is_dir($resolved) || !is_readable($resolved)) {
            throw new InvalidArgumentException(\sprintf('File access root "%s" is not a readable directory.', $root));
        }

        if ($maxIncludeDepth < 1 || $maxIncludeDepth > 256) {
            throw new InvalidArgumentException(\sprintf('Maximum include depth must be between 1 and 256, got %d.', $maxIncludeDepth));
        }

        $normalizedRoot = rtrim($resolved, \DIRECTORY_SEPARATOR);

        return new self('' === $normalizedRoot ? \DIRECTORY_SEPARATOR : $normalizedRoot, $maxIncludeDepth);
    }

    public function read(string $path, ?string $sourcePath = null): FileContent
    {
        $logicalPath = $this->logicalPath($path, $sourcePath);
        $prefix = \DIRECTORY_SEPARATOR === $this->root
            ? \DIRECTORY_SEPARATOR
            : $this->root.\DIRECTORY_SEPARATOR;
        $candidate = $prefix.str_replace('/', \DIRECTORY_SEPARATOR, $logicalPath);
        $resolved = realpath($candidate);

        if (
            false === $resolved
            || !str_starts_with($resolved, $prefix)
            || !is_file($resolved)
            || !is_readable($resolved)
        ) {
            throw new FileReadException(\sprintf('File-reading directive path "%s" is unavailable within the configured root.', $path));
        }

        $bytes = @file_get_contents($resolved);

        if (false === $bytes) {
            throw new FileReadException(\sprintf('Unable to read authorized file "%s".', $logicalPath));
        }

        return new FileContent($logicalPath, $bytes);
    }

    private function logicalPath(string $path, ?string $sourcePath): string
    {
        if ('' === $path || str_contains($path, "\0") || str_contains($path, '://')) {
            throw new FileReadException('File-reading directive path is empty, contains a null byte, or is a URL.');
        }

        $path = str_replace('\\', '/', $path);

        if (1 === preg_match('/^[A-Za-z]:\//', $path)) {
            throw new FileReadException(\sprintf('Absolute file-reading directive path "%s" is not allowed.', $path));
        }

        if (str_starts_with($path, '/')) {
            $combined = substr($path, 1);
        } else {
            $sourceDirectory = null === $sourcePath ? '' : dirname(str_replace('\\', '/', $sourcePath));
            $combined = ('.' === $sourceDirectory || '' === $sourceDirectory)
                ? $path
                : $sourceDirectory.'/'.$path;
        }

        $parts = [];

        foreach (explode('/', $combined) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }

            if ('..' === $part) {
                if ([] === $parts) {
                    throw new FileReadException(\sprintf('File-reading directive path "%s" traverses above the configured root.', $path));
                }

                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        if ([] === $parts) {
            throw new FileReadException(\sprintf('File-reading directive path "%s" does not name a file.', $path));
        }

        return implode('/', $parts);
    }
}
