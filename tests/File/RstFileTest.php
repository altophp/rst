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

namespace Alto\Rst\Tests\File;

use Alto\Rst\Exception\FileConflictException;
use Alto\Rst\Exception\FileReadException;
use Alto\Rst\Exception\FileWriteException;
use Alto\Rst\File\RstFile;
use Alto\Rst\File\SaveOptions;
use Alto\Rst\Node\Section;
use Alto\Rst\Profile\Profile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RstFile::class)]
#[CoversClass(SaveOptions::class)]
#[CoversClass(FileConflictException::class)]
#[CoversClass(FileWriteException::class)]
final class RstFileTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $paths = [];

    /**
     * @var list<string>
     */
    private array $directories = [];

    public function testOpensEditsDiffsSavesAndRebasesAFile(): void
    {
        $path = $this->writeTempFile("Title\n=====\n\nOld.\n");
        $file = RstFile::open($path);

        self::assertSame($path, $file->path());
        self::assertSame('docutils', $file->profile()->name);
        $file->editor()->replaceSectionBody(self::section($file), 'New.');

        self::assertSame(
            "--- {$path}\n+++ {$path}\n@@ -1,4 +1,4 @@\n Title\n =====\n \n-Old.\n+New.\n",
            $file->diff()->toUnifiedString(),
        );

        $file->save();

        self::assertSame("Title\n=====\n\nNew.\n", file_get_contents($path));
        self::assertSame([], $file->editor()->patches());
        self::assertTrue($file->diff()->isEmpty());

        $file->editor()->replaceSectionBody(self::section($file), 'Again.');
        $file->save();
        self::assertSame("Title\n=====\n\nAgain.\n", file_get_contents($path));
    }

    public function testAtomicSavePreservesBomCrLfUtf8AndPermissions(): void
    {
        $path = $this->writeTempFile("\xEF\xBB\xBFTitre\r\n=====\r\n\r\nAncien.\r\n");
        chmod($path, 0o640);
        $file = RstFile::open($path, Profile::symfony());

        $file->editor()->replaceSectionBody(self::section($file), 'Édité.');
        $file->save();

        clearstatcache(true, $path);
        self::assertSame(
            "\xEF\xBB\xBFTitre\r\n=====\r\n\r\nÉdité.\r\n",
            file_get_contents($path),
        );
        self::assertSame(0o640, fileperms($path) & 0o777);
        self::assertTrue($file->source()->hasBom());
        self::assertSame('symfony', $file->profile()->name);
    }

    public function testProtectedSaveRejectsExternalChangesAndKeepsPendingEdits(): void
    {
        $path = $this->writeTempFile("Title\n=====\n\nOld.\n");
        $file = RstFile::open($path);
        $file->editor()->replaceSectionBody(self::section($file), 'Local.');
        $diff = $file->diff()->toUnifiedString();
        file_put_contents($path, "Title\n=====\n\nExternal.\n");

        try {
            $file->save();
            self::fail('Expected the external change to prevent saving.');
        } catch (FileConflictException $error) {
            self::assertSame($path, $error->path);
            self::assertSame(
                \sprintf('RST file "%s" changed after it was opened.', $path),
                $error->getMessage(),
            );
            self::assertSame("Title\n=====\n\nExternal.\n", file_get_contents($path));
            self::assertSame($diff, $file->diff()->toUnifiedString());
            self::assertNotSame([], $file->editor()->patches());
        }
    }

    public function testCompareBeforeWriteCanBeDisabledExplicitly(): void
    {
        $path = $this->writeTempFile("Title\n=====\n\nOld.\n");
        $file = RstFile::open($path);
        $file->editor()->replaceSectionBody(self::section($file), 'Local.');
        file_put_contents($path, "Title\n=====\n\nExternal.\n");

        $file->save(new SaveOptions(compareBeforeWrite: false));

        self::assertSame("Title\n=====\n\nLocal.\n", file_get_contents($path));
    }

    public function testUnchangedSaveIsANoOp(): void
    {
        $path = $this->writeTempFile("Title\n=====\n");
        $file = RstFile::open($path);
        $inode = fileinode($path);

        $file->save();

        self::assertSame($inode, fileinode($path));
        self::assertSame("Title\n=====\n", file_get_contents($path));
    }

    public function testNonAtomicSaveWritesAndRebases(): void
    {
        $path = $this->writeTempFile("Title\n=====\n\nOld.\n");
        $file = RstFile::open($path);
        $file->editor()->replaceSectionBody(self::section($file), 'New.');

        $file->save(new SaveOptions(atomic: false));

        self::assertSame("Title\n=====\n\nNew.\n", file_get_contents($path));
        self::assertSame([], $file->editor()->patches());
    }

    public function testProtectedSaveRejectsADeletedOpenedFile(): void
    {
        $path = $this->writeTempFile("Title\n=====\n\nOld.\n");
        $file = RstFile::open($path);
        $file->editor()->replaceSectionBody(self::section($file), 'New.');
        unlink($path);

        try {
            $file->save();
            self::fail('Expected deletion to conflict with saving.');
        } catch (FileConflictException $error) {
            self::assertSame($path, $error->path);
            self::assertFileDoesNotExist($path);
            self::assertNotSame([], $file->editor()->patches());
        }
    }

    public function testSaveAsCopiesUnchangedBytesAndTracksTheNewPath(): void
    {
        $source = $this->writeTempFile("Title\r\n=====\r\n");
        $target = $this->tempPath();
        $file = RstFile::open($source);

        $file->saveAs($target);

        self::assertSame($target, $file->path());
        self::assertSame("Title\r\n=====\r\n", file_get_contents($target));

        $file->editor()->replaceSectionBody(self::section($file), 'Body.');
        $file->save();
        self::assertSame("Title\r\n=====\r\n\r\nBody.\r\n", file_get_contents($target));
        self::assertSame("Title\r\n=====\r\n", file_get_contents($source));
    }

    public function testProtectedSaveAsRejectsAnExistingUnopenedTarget(): void
    {
        $source = $this->writeTempFile("Title\n=====\n");
        $target = $this->writeTempFile("Existing\n");
        $file = RstFile::open($source);

        try {
            $file->saveAs($target);
            self::fail('Expected the existing target to prevent saveAs.');
        } catch (FileConflictException $error) {
            self::assertSame($target, $error->path);
            self::assertSame("Existing\n", file_get_contents($target));
            self::assertSame($source, $file->path());
        }
    }

    public function testFailedAtomicSaveLeavesOriginalAndPendingEditsIntact(): void
    {
        $path = $this->writeTempFile("Title\n=====\n\nOld.\n");
        $file = RstFile::open($path);
        $file->editor()->replaceSectionBody(self::section($file), 'Local.');
        $missing = \dirname($path).'/missing-'.bin2hex(random_bytes(4)).'/copy.rst';

        try {
            $file->saveAs($missing);
            self::fail('Expected the missing parent directory to reject saveAs.');
        } catch (FileWriteException) {
            self::assertSame("Title\n=====\n\nOld.\n", file_get_contents($path));
            self::assertNotSame([], $file->editor()->patches());
            self::assertSame($path, $file->path());
        }
    }

    public function testNonAtomicSaveAndAtomicSaveAsRejectNonRegularTargets(): void
    {
        $path = $this->writeTempFile("Title\n=====\n");
        $file = RstFile::open($path);

        foreach ([new SaveOptions(atomic: false), new SaveOptions()] as $options) {
            try {
                $file->saveAs(\sys_get_temp_dir(), $options);
                self::fail('Expected a directory target to be rejected.');
            } catch (FileWriteException $error) {
                self::assertStringContainsString('non-regular RST file', $error->getMessage());
            }
        }
    }

    public function testSaveRejectsSymlinkTargetsWithoutReplacingTheLink(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('Symbolic links are POSIX-specific.');
        }

        $target = $this->writeTempFile("Title\n=====\n\nOld.\n");
        $link = $this->tempPath();

        if (!symlink($target, $link)) {
            self::markTestSkipped('The filesystem does not allow symbolic links.');
        }

        $file = RstFile::open($link);
        $file->editor()->replaceSectionBody(self::section($file), 'New.');

        try {
            $file->save();
            self::fail('Expected the symlink target to be rejected.');
        } catch (FileWriteException $error) {
            self::assertStringContainsString('RST symlink', $error->getMessage());
            self::assertTrue(is_link($link));
            self::assertSame("Title\n=====\n\nOld.\n", file_get_contents($target));
            self::assertNotSame([], $file->editor()->patches());
        }
    }

    public function testRelativeOpenRemainsAnchoredAfterWorkingDirectoryChanges(): void
    {
        $first = $this->tempDirectory();
        $second = $this->tempDirectory();
        $firstPath = $first.'/guide.rst';
        $secondPath = $second.'/guide.rst';
        file_put_contents($firstPath, "Title\n=====\n");
        file_put_contents($secondPath, "Title\n=====\n");
        $this->paths[] = $firstPath;
        $this->paths[] = $secondPath;
        $workingDirectory = getcwd();
        self::assertIsString($workingDirectory);

        try {
            self::assertTrue(chdir($first));
            $file = RstFile::open('guide.rst');
            $file->editor()->replaceSectionBody(self::section($file), 'First.');

            self::assertTrue(chdir($second));
            $file->save();
        } finally {
            chdir($workingDirectory);
        }

        self::assertSame("Title\n=====\n\nFirst.\n", file_get_contents($firstPath));
        self::assertSame("Title\n=====\n", file_get_contents($secondPath));
    }

    public function testOpenRejectsMissingAndNonRegularPaths(): void
    {
        foreach ([$this->tempPath(), \sys_get_temp_dir()] as $path) {
            try {
                RstFile::open($path);
                self::fail('Expected a non-file path to be rejected.');
            } catch (FileReadException $error) {
                self::assertStringContainsString('Unable to read RST file', $error->getMessage());
            }
        }
    }

    public function testOpenRejectsEmptyMissingParentAndDanglingSymlinkPaths(): void
    {
        $missingParent = \sys_get_temp_dir().'/alto-rst-missing-'.bin2hex(random_bytes(8)).'/guide.rst';
        $paths = ['', $missingParent];

        if ('\\' !== \DIRECTORY_SEPARATOR) {
            $link = $this->tempPath();
            $missing = $this->tempPath();

            if (symlink($missing, $link)) {
                $paths[] = $link;
            }
        }

        foreach ($paths as $path) {
            try {
                RstFile::open($path);
                self::fail(\sprintf('Expected path "%s" to be rejected.', $path));
            } catch (FileReadException $error) {
                self::assertStringContainsString('Unable to read RST file', $error->getMessage());
            }
        }
    }

    public function testSaveAsRejectsAnEmptyPath(): void
    {
        $file = RstFile::open($this->writeTempFile("Title\n=====\n"));

        try {
            $file->saveAs('');
            self::fail('Expected an empty saveAs path to be rejected.');
        } catch (FileWriteException $error) {
            self::assertSame('', $error->path);
            self::assertStringContainsString('Unable to resolve parent directory', $error->getMessage());
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (false !== @lstat($path)) {
                @unlink($path);
            }
        }

        foreach ($this->directories as $directory) {
            @rmdir($directory);
        }
    }

    private static function section(RstFile $file): Section
    {
        $node = $file->parsed()->document()->children()[0] ?? null;
        self::assertInstanceOf(Section::class, $node);

        return $node;
    }

    private function writeTempFile(string $bytes): string
    {
        $path = $this->tempPath();
        file_put_contents($path, $bytes);

        return $path;
    }

    private function tempPath(): string
    {
        $path = \sys_get_temp_dir().'/alto-rst-save-'.bin2hex(random_bytes(8)).'.rst';
        $this->paths[] = $path;

        return $path;
    }

    private function tempDirectory(): string
    {
        $directory = \sys_get_temp_dir().'/alto-rst-save-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $this->directories[] = $directory;

        return $directory;
    }
}
