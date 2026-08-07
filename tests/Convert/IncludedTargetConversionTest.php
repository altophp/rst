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

namespace Alto\Rst\Tests\Convert;

use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\ProjectConverter;
use Alto\Rst\Convert\Writer\MarkdownWriter;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Security\FileAccessPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectConverter::class)]
#[CoversClass(MarkdownWriter::class)]
final class IncludedTargetConversionTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/alto-rst-included-target-'.bin2hex(random_bytes(8));
        mkdir($this->root);
        mkdir($this->root.'/_includes');
    }

    protected function tearDown(): void
    {
        if ('' === $this->root || !is_dir($this->root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->root);
    }

    public function testIncludedSectionTargetsResolveBeforeTheIncludeIsWritten(): void
    {
        file_put_contents(
            $this->root.'/index.rst',
            "See `included option`_.\n\n.. include:: /_includes/option.rst.inc\n",
        );
        file_put_contents(
            $this->root.'/_includes/option.rst.inc',
            "``Included option``\n~~~~~~~~~~~~~~~~~~~\n\nDetails.\n",
        );

        $result = new ProjectConverter()->convertDirectory(
            $this->root,
            Profile::symfony(),
            ConversionOptions::symfony(FileAccessPolicy::rootedAt($this->root)),
        );

        self::assertStringContainsString(
            '[included option](#included-option)',
            $result->outputs()['index.md'],
        );
        self::assertSame(
            ['directive:include' => 1],
            $result->report->countsByConstruct(),
        );
        $file = $result->file('index.rst');
        self::assertNotNull($file);
        self::assertFalse($file->referenceProblems->hasProblems());
        self::assertSame(
            ['reference/unresolved-target'],
            array_column($file->sourceReferenceProblems->problems(), 'code'),
        );
    }

    public function testNestedIncludesShareTheirTargetsWithTheParent(): void
    {
        file_put_contents(
            $this->root.'/index.rst',
            "See `nested option`_.\n\n.. include:: /_includes/first.rst.inc\n",
        );
        file_put_contents(
            $this->root.'/_includes/first.rst.inc',
            ".. include:: second.rst.inc\n",
        );
        file_put_contents(
            $this->root.'/_includes/second.rst.inc',
            "Nested option\n~~~~~~~~~~~~~\n",
        );

        $result = new ProjectConverter()->convertDirectory(
            $this->root,
            Profile::symfony(),
            ConversionOptions::symfony(FileAccessPolicy::rootedAt($this->root)),
        );

        self::assertStringContainsString(
            '[nested option](#nested-option)',
            $result->outputs()['index.md'],
        );
        self::assertSame(
            ['directive:include' => 2],
            $result->report->countsByConstruct(),
        );
    }

    public function testDirectiveBodyReferencesUseIncludedTargets(): void
    {
        file_put_contents(
            $this->root.'/index.rst',
            ".. warning::\n\n"
            ."    See `included option`_.\n\n"
            .".. include:: /_includes/option.rst.inc\n",
        );
        file_put_contents(
            $this->root.'/_includes/option.rst.inc',
            "Included option\n~~~~~~~~~~~~~~~\n",
        );

        $result = new ProjectConverter()->convertDirectory(
            $this->root,
            Profile::symfony(),
            ConversionOptions::symfony(FileAccessPolicy::rootedAt($this->root)),
        );

        self::assertStringContainsString(
            '[included option](#included-option)',
            $result->outputs()['index.md'],
        );
        $file = $result->file('index.rst');
        self::assertNotNull($file);
        self::assertFalse($file->referenceProblems->hasProblems());
        self::assertSame(
            ['reference/unresolved-target'],
            array_column($file->sourceReferenceProblems->problems(), 'code'),
        );
    }

    public function testIncludedTargetsRemainUnavailableWithoutFileAuthority(): void
    {
        file_put_contents(
            $this->root.'/index.rst',
            "See `included option`_.\n\n.. include:: /_includes/option.rst.inc\n",
        );
        file_put_contents(
            $this->root.'/_includes/option.rst.inc',
            "Included option\n~~~~~~~~~~~~~~~\n",
        );

        $result = new ProjectConverter()->convertDirectory(
            $this->root,
            Profile::symfony(),
            ConversionOptions::symfony(),
        );

        self::assertStringContainsString(
            '[included option][included option]',
            $result->outputs()['index.md'],
        );
        self::assertSame(
            [
                'directive:include' => 1,
                'link:unresolved' => 1,
            ],
            $result->report->countsByConstruct(),
        );
        $file = $result->file('index.rst');
        self::assertNotNull($file);
        self::assertSame(
            ['reference/unresolved-target'],
            array_column($file->referenceProblems->problems(), 'code'),
        );
        self::assertEquals($file->sourceReferenceProblems, $file->referenceProblems);
    }
}
