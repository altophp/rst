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

namespace Alto\Rst\Tests\Security;

use Alto\Rst\Exception\FileReadException;
use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Security\FileAccessPolicy;
use Alto\Rst\Security\FileContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileAccessPolicy::class)]
#[CoversClass(FileContent::class)]
final class FileAccessPolicyTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/alto-rst-policy-' . bin2hex(random_bytes(6));
        mkdir($this->root);
        mkdir($this->root . '/guide');
        mkdir($this->root . '/_includes');
        file_put_contents($this->root . '/_includes/shared.rst.inc', "Shared.\n");
        file_put_contents($this->root . '/guide/local.rst.inc', "Local.\n");
    }

    protected function tearDown(): void
    {
        foreach (['_includes/shared.rst.inc', 'guide/local.rst.inc'] as $path) {
            @unlink($this->root . '/' . $path);
        }

        @unlink($this->root . '/guide/escape.rst.inc');
        @rmdir($this->root . '/_includes');
        @rmdir($this->root . '/guide');
        @rmdir($this->root);
    }

    public function testReadsRootRelativeAndSourceRelativePaths(): void
    {
        $policy = FileAccessPolicy::rootedAt($this->root);

        $rootRelative = $policy->read('/_includes/shared.rst.inc', 'guide/page.rst');
        $sourceRelative = $policy->read('local.rst.inc', 'guide/page.rst');

        self::assertSame('_includes/shared.rst.inc', $rootRelative->path);
        self::assertSame("Shared.\n", $rootRelative->bytes);
        self::assertSame('guide/local.rst.inc', $sourceRelative->path);
        self::assertSame("Local.\n", $sourceRelative->bytes);
    }

    public function testRejectsTraversalAboveTheRoot(): void
    {
        $this->expectException(FileReadException::class);
        $this->expectExceptionMessage('traverses above');

        FileAccessPolicy::rootedAt($this->root)->read('../../outside.rst', 'guide/page.rst');
    }

    public function testRejectsUrls(): void
    {
        $this->expectException(FileReadException::class);

        FileAccessPolicy::rootedAt($this->root)->read('https://example.com/file.rst');
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsOtherInvalidPathShapes(string $path): void
    {
        $this->expectException(FileReadException::class);

        FileAccessPolicy::rootedAt($this->root)->read($path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'windows absolute' => ['C:\\secret.rst'];
        yield 'root directory' => ['/'];
        yield 'null byte' => ["file\0.rst"];
    }

    public function testNormalizesDotSegmentsWithoutASourcePath(): void
    {
        $content = FileAccessPolicy::rootedAt($this->root)->read(
            './guide/../_includes/shared.rst.inc',
        );

        self::assertSame('_includes/shared.rst.inc', $content->path);
    }

    public function testNormalizesWindowsSeparatorsAndRepeatedSeparators(): void
    {
        $content = FileAccessPolicy::rootedAt($this->root)->read(
            '.\\guide\\\\..\\_includes//shared.rst.inc',
        );

        self::assertSame('_includes/shared.rst.inc', $content->path);
        self::assertSame("Shared.\n", $content->bytes);
    }

    public function testRejectsTraversalInTheSourcePath(): void
    {
        $this->expectException(FileReadException::class);
        $this->expectExceptionMessage('traverses above');

        FileAccessPolicy::rootedAt($this->root)->read('local.rst.inc', '../guide/page.rst');
    }

    public function testCanExplicitlyUseTheFilesystemRoot(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'alto-rst-root-policy-');
        self::assertIsString($file);
        file_put_contents($file, "Rooted.\n");

        try {
            $content = FileAccessPolicy::rootedAt('/')->read($file);
            self::assertSame("Rooted.\n", $content->bytes);
        } finally {
            @unlink($file);
        }
    }

    public function testRejectsASymlinkThatEscapesTheRoot(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'alto-rst-outside-');
        self::assertIsString($outside);
        symlink($outside, $this->root . '/guide/escape.rst.inc');

        try {
            $this->expectException(FileReadException::class);
            FileAccessPolicy::rootedAt($this->root)->read('escape.rst.inc', 'guide/page.rst');
        } finally {
            @unlink($outside);
        }
    }

    public function testRejectsASymlinkedDirectoryThatEscapesTheRoot(): void
    {
        $outside = sys_get_temp_dir() . '/alto-rst-outside-dir-' . bin2hex(random_bytes(6));
        mkdir($outside);
        file_put_contents($outside . '/secret.rst.inc', "Secret.\n");
        symlink($outside, $this->root . '/guide/escape-dir');

        try {
            $this->expectException(FileReadException::class);
            FileAccessPolicy::rootedAt($this->root)->read('escape-dir/secret.rst.inc', 'guide/page.rst');
        } finally {
            @unlink($this->root . '/guide/escape-dir');
            @unlink($outside . '/secret.rst.inc');
            @rmdir($outside);
        }
    }

    public function testAllowsASymlinkWhoseTargetStaysInsideTheRoot(): void
    {
        symlink('../_includes', $this->root . '/guide/includes');

        try {
            $content = FileAccessPolicy::rootedAt($this->root)->read(
                'includes/shared.rst.inc',
                'guide/page.rst',
            );

            self::assertSame('guide/includes/shared.rst.inc', $content->path);
            self::assertSame("Shared.\n", $content->bytes);
        } finally {
            @unlink($this->root . '/guide/includes');
        }
    }

    public function testRejectsAnInvalidRootAndDepth(): void
    {
        try {
            FileAccessPolicy::rootedAt($this->root . '/missing');
            self::fail('Invalid root was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('not a readable directory', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        FileAccessPolicy::rootedAt($this->root, 0);
    }
}
