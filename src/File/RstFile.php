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

namespace Alto\Rst\File;

use Alto\Rst\Edit\Editor;
use Alto\Rst\Exception\FileConflictException;
use Alto\Rst\Exception\FileReadException;
use Alto\Rst\Exception\FileWriteException;
use Alto\Rst\Operation\Diff;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Parser\ParseResult;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Source\Source;

/**
 * One parsed RST file with pending source edits and conflict-safe writes.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class RstFile
{
    private Source $source;

    private ParseResult $result;

    private Editor $editor;

    private string $sourceFingerprint;

    private function __construct(
        private string $path,
        private string $anchoredPath,
        private readonly Profile $profile,
        string $bytes,
    ) {
        $this->source = Source::fromString($bytes);
        $this->result = new BlockParser()->parse($this->source, $profile);
        $this->editor = new Editor($this->source, $this->result);
        $this->sourceFingerprint = self::fingerprint($bytes);
    }

    public static function open(string $path, ?Profile $profile = null): self
    {
        $anchoredPath = self::anchorReadPath($path);
        $metadata = @lstat($anchoredPath);

        if (false === $metadata || (!is_file($anchoredPath) && !is_link($anchoredPath))) {
            throw new FileReadException(\sprintf('Unable to read RST file "%s".', $path));
        }

        $bytes = @file_get_contents($anchoredPath);

        if (false === $bytes) {
            throw new FileReadException(\sprintf('Unable to read RST file "%s".', $path));
        }

        return new self($path, $anchoredPath, $profile ?? Profile::docutils(), $bytes);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function profile(): Profile
    {
        return $this->profile;
    }

    public function source(): Source
    {
        return $this->source;
    }

    public function parsed(): ParseResult
    {
        return $this->result;
    }

    public function editor(): Editor
    {
        return $this->editor;
    }

    public function toRst(): string
    {
        return $this->editor->toRst();
    }

    public function diff(int $context = 3): Diff
    {
        return Diff::between(
            $this->source->bytes,
            $this->toRst(),
            $this->path,
            $this->path,
            $context,
        );
    }

    public function save(?SaveOptions $options = null): void
    {
        if ([] === $this->editor->patches()) {
            return;
        }

        $this->writeAndRebase(
            $this->path,
            $this->anchoredPath,
            $options ?? new SaveOptions(),
        );
    }

    public function saveAs(string $path, ?SaveOptions $options = null): void
    {
        $anchoredPath = self::anchorWritePath($path);
        $this->writeAndRebase($path, $anchoredPath, $options ?? new SaveOptions());
        $this->path = $path;
        $this->anchoredPath = $anchoredPath;
    }

    private function writeAndRebase(string $path, string $anchoredPath, SaveOptions $options): void
    {
        $bytes = $this->toRst();
        $source = Source::fromString($bytes);
        $result = new BlockParser()->parse($source, $this->profile);

        $this->write($path, $anchoredPath, $bytes, $options);
        $this->source = $source;
        $this->result = $result;
        $this->editor = new Editor($source, $result);
        $this->sourceFingerprint = self::fingerprint($bytes);
    }

    private function write(string $path, string $anchoredPath, string $bytes, SaveOptions $options): void
    {
        $writePath = $this->resolveWritePath($path, $anchoredPath);

        if (!$options->atomic) {
            $this->assertTargetUnchanged($path, $anchoredPath, $writePath, $options);

            if (false === @file_put_contents($writePath, $bytes)) {
                throw new FileWriteException($path, \sprintf('Unable to write RST file "%s".', $path));
            }

            return;
        }

        $metadata = @lstat($writePath);
        $permissions = false === $metadata ? 0o666 & ~umask() : @fileperms($writePath);

        if (false !== $metadata && false === $permissions) {
            throw new FileWriteException($path, \sprintf('Unable to read permissions for RST file "%s".', $path));
        }

        $temporary = @tempnam(\dirname($writePath), '.alto-rst-');

        if (false === $temporary) {
            throw new FileWriteException($path, \sprintf('Unable to write RST file "%s".', $path));
        }

        try {
            if (false === @file_put_contents($temporary, $bytes)) {
                throw new FileWriteException($path, \sprintf('Unable to write RST file "%s".', $path));
            }

            if (!@chmod($temporary, $permissions & 0o7777)) {
                $action = false === $metadata ? 'set' : 'restore';

                throw new FileWriteException($path, \sprintf('Unable to %s permissions for RST file "%s".', $action, $path));
            }

            $this->assertTargetUnchanged($path, $anchoredPath, $writePath, $options);

            if (!@rename($temporary, $writePath)) {
                throw new FileWriteException($path, \sprintf('Unable to write RST file "%s".', $path));
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function assertTargetUnchanged(
        string $path,
        string $anchoredPath,
        string $writePath,
        SaveOptions $options,
    ): void {
        if (!$options->compareBeforeWrite) {
            return;
        }

        if ($anchoredPath !== $this->anchoredPath) {
            if (false !== @lstat($writePath) || false !== @lstat($anchoredPath)) {
                throw new FileConflictException($path, \sprintf('RST file "%s" already exists and was not opened by this document.', $path));
            }

            return;
        }

        $current = @file_get_contents($writePath);

        if (
            false === $current
            || !hash_equals($this->sourceFingerprint, self::fingerprint($current))
        ) {
            throw new FileConflictException($path, \sprintf('RST file "%s" changed after it was opened.', $path));
        }
    }

    private function resolveWritePath(string $path, string $anchoredPath): string
    {
        $metadata = @lstat($anchoredPath);

        if (false === $metadata) {
            return $anchoredPath;
        }

        $type = $metadata['mode'] & 0o170000;

        if (0o120000 === $type) {
            throw new FileWriteException($path, \sprintf('Refusing to write RST symlink "%s".', $path));
        }

        if (0o100000 !== $type) {
            throw new FileWriteException($path, \sprintf('Refusing to write non-regular RST file "%s".', $path));
        }

        return $anchoredPath;
    }

    private static function anchorReadPath(string $path): string
    {
        if ('' === $path) {
            throw new FileReadException('Unable to read RST file "".');
        }

        $directory = realpath(\dirname($path));

        if (false === $directory) {
            throw new FileReadException(\sprintf('Unable to read RST file "%s".', $path));
        }

        return rtrim($directory, \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR.basename($path);
    }

    private static function anchorWritePath(string $path): string
    {
        if ('' === $path) {
            throw new FileWriteException('', 'Unable to resolve parent directory for RST file "".');
        }

        $directory = realpath(\dirname($path));

        if (false === $directory) {
            throw new FileWriteException($path, \sprintf('Unable to resolve parent directory for RST file "%s".', $path));
        }

        return rtrim($directory, \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR.basename($path);
    }

    private static function fingerprint(string $bytes): string
    {
        return hash('sha256', $bytes, true);
    }
}
