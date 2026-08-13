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
use Alto\Rst\Convert\ConversionStatus;
use Alto\Rst\Convert\IssueKind;
use Alto\Rst\Convert\ProjectConversionResult;
use Alto\Rst\Convert\ProjectConverter;
use Alto\Rst\Convert\ProjectFileConversion;
use Alto\Rst\Convert\Writer\MarkdownWriter;
use Alto\Rst\Exception\FileReadException;
use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Security\FileAccessPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectConverter::class)]
#[CoversClass(ProjectConversionResult::class)]
#[CoversClass(ProjectFileConversion::class)]
#[CoversClass(MarkdownWriter::class)]
#[CoversClass(FileReadException::class)]
final class ProjectConverterTest extends TestCase
{
    public function testConvertsSourcesInCanonicalPathOrderWithAnAggregateReport(): void
    {
        $result = new ProjectConverter()->convertSources([
            './zeta.rst' => "Zeta\n====\n\n.. unknown:: value\n",
            'guide\\start.rst' => "Start\n=====\n\nSee :doc:`../zeta`.\n",
        ], Profile::sphinx());

        self::assertSame(
            ['guide/start.rst', 'zeta.rst'],
            array_map(static fn(ProjectFileConversion $file): string => $file->sourcePath, $result->files),
        );
        self::assertSame(['guide/start.md', 'zeta.md'], array_keys($result->outputs()));
        self::assertStringContainsString('[Zeta](../zeta.md)', $result->outputs()['guide/start.md']);
        self::assertSame(
            ['directive:unknown' => 1, 'role:doc' => 1],
            $result->report->countsByConstruct(),
        );
        self::assertSame(ConversionStatus::Blocked, $result->status());
        self::assertFalse($result->isComplete());
        self::assertFalse($result->isLossless());
        self::assertFalse($result->isExact());
        self::assertCount(1, $result->filesWithIssueKind(IssueKind::Unsupported));
        self::assertFalse($result->projectReferenceProblems->hasProblems());
    }

    public function testKeepsReportsForEveryFileAndProjectReferences(): void
    {
        $result = new ProjectConverter()->convertSources([
            'broken.rst' => "Title\n=====\n\nSee :doc:`missing`.\n",
        ], Profile::sphinx());

        $file = $result->file('./broken.rst');

        self::assertInstanceOf(ProjectFileConversion::class, $file);
        self::assertSame('broken.md', $file->targetPath);
        self::assertSame($file, $result->file('guide/../broken.rst'));
        self::assertNull($result->file('../broken.rst'));
        self::assertNull($result->file('../../broken.rst'));
        self::assertNull($result->file('/broken.rst'));
        self::assertNull($result->file('missing.rst'));
        self::assertFalse($file->parseProblems->hasProblems());
        self::assertFalse($file->referenceProblems->hasProblems());
        self::assertSame(['role:doc' => 1], $file->conversion->report->countsByConstruct());
        self::assertSame(ConversionStatus::Review, $file->status());
        self::assertTrue($file->isComplete());
        self::assertFalse($file->isLossless());
        self::assertSame(
            'reference/unresolved-project-target',
            $result->projectReferenceProblems->problems()[0]->code,
        );
    }

    public function testDirectoryConversionIsRecursiveAndNeverWritesOutputs(): void
    {
        $root = $this->tempDirectory();
        $outside = tempnam(sys_get_temp_dir(), 'alto-rst-include-');
        self::assertIsString($outside);
        file_put_contents($outside, "Sensitive outside content.\n");
        mkdir($root . '/guide');
        file_put_contents(
            $root . '/index.rst',
            "Home\n====\n\nSee :doc:`guide/page`.\n\n.. include:: " . $outside . "\n",
        );
        file_put_contents($root . '/guide/page.rst', "Page\n====\n");
        file_put_contents($root . '/guide/ignore.txt', "Ignored\n=======\n");

        try {
            $result = new ProjectConverter()->convertDirectory($root, Profile::sphinx());

            self::assertSame(['guide/page.md', 'index.md'], array_keys($result->outputs()));
            self::assertStringContainsString('[Page](guide/page.md)', $result->outputs()['index.md']);
            self::assertStringNotContainsString('Sensitive outside content.', $result->outputs()['index.md']);
            self::assertFileDoesNotExist($root . '/index.md');
            self::assertFileDoesNotExist($root . '/guide/page.md');
        } finally {
            unlink($outside);
            $this->removeDirectory($root);
        }
    }

    public function testExplicitRootedPolicyExpandsAnIncludeWithoutCreatingAnOutputFile(): void
    {
        $root = $this->tempDirectory();
        mkdir($root . '/_includes');
        file_put_contents($root . '/index.rst', ".. include:: /_includes/shared.rst.inc\n");
        file_put_contents($root . '/_includes/shared.rst.inc', "Shared *content*.\n");

        try {
            $result = new ProjectConverter()->convertDirectory(
                $root,
                Profile::sphinx(),
                new ConversionOptions(fileAccessPolicy: FileAccessPolicy::rootedAt($root)),
            );

            self::assertSame(['index.md'], array_keys($result->outputs()));
            self::assertSame("Shared *content*.\n", $result->outputs()['index.md']);
            self::assertSame(['directive:include' => 1], $result->report->countsByConstruct());
            self::assertSame(ConversionStatus::Review, $result->status());
            self::assertTrue($result->isComplete());
            self::assertFalse($result->isLossless());
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testIncludedReferencesResolveRelativeToTheOwningDocument(): void
    {
        $root = $this->tempDirectory();
        mkdir($root . '/guide');
        mkdir($root . '/fragments');
        file_put_contents($root . '/guide/index.rst', ".. include:: ../fragments/shared.rst.inc\n");
        file_put_contents($root . '/guide/target.rst', "Target\n======\n");
        file_put_contents($root . '/fragments/shared.rst.inc', "See :doc:`target`.\n");

        try {
            $result = new ProjectConverter()->convertDirectory(
                $root,
                Profile::sphinx(),
                new ConversionOptions(fileAccessPolicy: FileAccessPolicy::rootedAt($root)),
            );

            self::assertStringContainsString('[Target](target.md)', $result->outputs()['guide/index.md']);
            self::assertSame(['directive:include' => 1, 'role:doc' => 1], $result->report->countsByConstruct());
            self::assertCount(1, $result->report->ofKind(IssueKind::Lossy));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testSymfonyToctreeExpandsGlobIntoProjectLinks(): void
    {
        $result = new ProjectConverter()->convertSources([
            'guide/index.rst' => "Guide\n=====\n\n.. toctree::\n    :glob:\n    :maxdepth: 1\n\n    pages/*\n",
            'guide/pages/one.rst' => "First Page\n==========\n",
            'guide/pages/two.rst' => "Second Page\n===========\n",
        ], Profile::symfony(), ConversionOptions::symfony());

        self::assertStringContainsString('- [First Page](pages/one.md)', $result->outputs()['guide/index.md']);
        self::assertStringContainsString('- [Second Page](pages/two.md)', $result->outputs()['guide/index.md']);
        self::assertSame(['directive:toctree' => 1], $result->report->countsByConstruct());
        self::assertSame(ConversionStatus::Review, $result->status());
        self::assertTrue($result->isComplete());
        self::assertFalse($result->isLossless());
    }

    public function testSymfonyProjectReferenceResolvesAMarkupSectionTitle(): void
    {
        $result = new ProjectConverter()->convertSources([
            'index.rst' => "See :ref:`widget`.\n",
            'guide/widget.rst' => "``widget``\n==========\n",
        ], Profile::symfony(), ConversionOptions::symfony());

        self::assertFalse($result->projectReferenceProblems->hasProblems());
        self::assertSame(
            "See [widget](guide/widget.md#widget).\n",
            $result->outputs()['index.md'],
        );
    }

    public function testProjectReferenceResolvesInsideAReparsedDirectiveBody(): void
    {
        $result = new ProjectConverter()->convertSources([
            'index.rst' => ".. note::\n\n    See :doc:`guide`.\n",
            'guide.rst' => "Guide\n=====\n",
        ], Profile::sphinx());

        self::assertStringContainsString('[Guide](guide.md)', $result->outputs()['index.md']);
        self::assertSame(ConversionStatus::Tracked, $result->file('index.rst')?->status());
        self::assertCount(0, $result->report->ofKind(IssueKind::Lossy));
        self::assertSame(['role:doc' => 1], $result->report->countsByConstruct());
    }

    public function testExplicitRawAuthorityEmbedsInlineRawHtml(): void
    {
        $result = new ProjectConverter()->convertSources([
            'index.rst' => ".. raw:: html\n\n"
                . "    <object data=\"diagram.svg\" type=\"image/svg+xml\"></object>\n",
        ], Profile::symfony(), ConversionOptions::symfony(allowRawHtml: true));

        self::assertSame(
            "<object data=\"diagram.svg\" type=\"image/svg+xml\"></object>\n",
            $result->outputs()['index.md'],
        );
        self::assertSame(['directive:raw' => 1], $result->report->countsByConstruct());
        self::assertTrue($result->isLossless());
    }

    public function testRawHtmlRemainsDisabledWithoutExplicitRawAuthority(): void
    {
        $result = new ProjectConverter()->convertSources([
            'index.rst' => ".. raw:: html\n\n    <strong>unsafe</strong>\n",
        ], Profile::symfony(), ConversionOptions::symfony());

        self::assertStringContainsString('<!-- rst: directive raw -->', $result->outputs()['index.md']);
        self::assertCount(1, $result->report->ofKind(IssueKind::Unsupported));
        self::assertFalse($result->isLossless());
    }

    public function testRootedFilePolicyDoesNotAuthorizeRawHtml(): void
    {
        $root = $this->tempDirectory();
        file_put_contents($root . '/index.rst', ".. raw:: html\n\n    <strong>unsafe</strong>\n");

        try {
            $result = new ProjectConverter()->convertDirectory(
                $root,
                Profile::symfony(),
                ConversionOptions::symfony(FileAccessPolicy::rootedAt($root)),
            );

            self::assertCount(1, $result->report->ofKind(IssueKind::Unsupported));
            self::assertStringContainsString('<!-- rst: directive raw -->', $result->outputs()['index.md']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testToctreeReportsAMissingProjectTarget(): void
    {
        $result = new ProjectConverter()->convertSources([
            'index.rst' => ".. toctree::\n\n    missing\n",
        ], Profile::symfony(), ConversionOptions::symfony());

        self::assertCount(1, $result->report->ofKind(IssueKind::Unsupported));
        self::assertStringContainsString('<!-- rst: directive toctree target -->', $result->outputs()['index.md']);
    }

    public function testToctreeWildcardRequiresGlobOption(): void
    {
        $result = new ProjectConverter()->convertSources([
            'index.rst' => ".. toctree::\n\n    guide/*\n",
            'guide/page.rst' => "Page\n====\n",
        ], Profile::symfony(), ConversionOptions::symfony());

        self::assertCount(1, $result->report->ofKind(IssueKind::Unsupported));
        self::assertStringContainsString('<!-- rst: directive toctree glob -->', $result->outputs()['index.md']);
    }

    public function testToctreeReportsAnEmptyBody(): void
    {
        $result = new ProjectConverter()->convertSources([
            'index.rst' => ".. toctree::\n",
        ], Profile::symfony(), ConversionOptions::symfony());

        self::assertCount(1, $result->report->ofKind(IssueKind::Unsupported));
        self::assertStringContainsString('<!-- rst: directive toctree -->', $result->outputs()['index.md']);
    }

    public function testToctreeReportsAGlobWithoutMatches(): void
    {
        $result = new ProjectConverter()->convertSources([
            'index.rst' => ".. toctree::\n    :glob:\n\n    missing/*\n",
            'guide/page.rst' => "Page\n====\n",
        ], Profile::symfony(), ConversionOptions::symfony());

        self::assertCount(1, $result->report->ofKind(IssueKind::Unsupported));
        self::assertStringContainsString('<!-- rst: directive toctree glob -->', $result->outputs()['index.md']);
    }

    public function testToctreeResolvesTitledRelativeEntries(): void
    {
        $result = new ProjectConverter()->convertSources([
            'guide/index.rst' => ".. toctree::\n\n    Custom title <../target>\n    ./page\n",
            'guide/page.rst' => "Page\n====\n",
            'target.rst' => "Target\n======\n",
        ], Profile::symfony(), ConversionOptions::symfony());

        self::assertSame(
            "- [Custom title](../target.md)\n- [Page](page.md)\n",
            $result->outputs()['guide/index.md'],
        );
        self::assertSame(ConversionStatus::Review, $result->status());
        self::assertTrue($result->isComplete());
        self::assertFalse($result->isLossless());
    }

    public function testExplicitPolicyReportsAnIncludeCycle(): void
    {
        $root = $this->tempDirectory();
        file_put_contents($root . '/index.rst', ".. include:: loop.rst.inc\n");
        file_put_contents($root . '/loop.rst.inc', ".. include:: index.rst\n");

        try {
            $result = new ProjectConverter()->convertDirectory(
                $root,
                Profile::sphinx(),
                new ConversionOptions(fileAccessPolicy: FileAccessPolicy::rootedAt($root)),
            );

            self::assertStringContainsString('<!-- rst: directive include cycle -->', $result->outputs()['index.md']);
            self::assertSame(['directive:include' => 2], $result->report->countsByConstruct());
            self::assertFalse($result->isLossless());
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testNestedDirectiveKeepsItsIncludeCycleContext(): void
    {
        $root = $this->tempDirectory();
        file_put_contents($root . '/index.rst', ".. include:: fragment.rst.inc\n");
        file_put_contents(
            $root . '/fragment.rst.inc',
            ".. note::\n\n    .. include:: index.rst\n",
        );

        try {
            $result = new ProjectConverter()->convertDirectory(
                $root,
                Profile::sphinx(),
                new ConversionOptions(fileAccessPolicy: FileAccessPolicy::rootedAt($root)),
            );

            self::assertStringContainsString('<!-- rst: directive include cycle -->', $result->outputs()['index.md']);
            self::assertSame(['directive:include' => 2], $result->report->countsByConstruct());
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testConfigurationBlockKeepsItsIncludeCycleContext(): void
    {
        $root = $this->tempDirectory();
        file_put_contents($root . '/index.rst', ".. include:: fragment.rst.inc\n");
        file_put_contents(
            $root . '/fragment.rst.inc',
            ".. configuration-block::\n\n    .. include:: index.rst\n",
        );

        try {
            $result = new ProjectConverter()->convertDirectory(
                $root,
                Profile::symfony(),
                ConversionOptions::symfony(FileAccessPolicy::rootedAt($root)),
            );

            self::assertStringContainsString('<!-- rst: directive include cycle -->', $result->outputs()['index.md']);
            self::assertSame(
                [
                    'directive:include' => 2,
                    'directive:configuration-block' => 1,
                ],
                $result->report->countsByConstruct(),
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testExpandedIncludesUseDistinctFootnoteAndCitationAnchors(): void
    {
        $root = $this->tempDirectory();
        file_put_contents(
            $root . '/index.rst',
            ".. include:: a.rst.inc\n\n.. include:: b.rst.inc\n",
        );
        file_put_contents(
            $root . '/a.rst.inc',
            "See [1]_ and [CIT]_.\n\n.. [1] First note.\n.. [CIT] First citation.\n",
        );
        file_put_contents(
            $root . '/b.rst.inc',
            "See [1]_ and [CIT]_.\n\n.. [1] Second note.\n.. [CIT] Second citation.\n",
        );
        $aPrefix = 'a-rst-inc-' . substr(hash('sha256', 'a.rst.inc'), 0, 8);
        $bPrefix = 'b-rst-inc-' . substr(hash('sha256', 'b.rst.inc'), 0, 8);

        try {
            $result = new ProjectConverter()->convertDirectory(
                $root,
                Profile::symfony(),
                ConversionOptions::symfony(FileAccessPolicy::rootedAt($root)),
            );

            self::assertStringContainsString(
                "See [^fn-{$aPrefix}-1] and [CIT](#citation-{$aPrefix}-cit).",
                $result->outputs()['index.md'],
            );
            self::assertStringContainsString(
                "See [^fn-{$bPrefix}-1] and [CIT](#citation-{$bPrefix}-cit).",
                $result->outputs()['index.md'],
            );
            self::assertStringContainsString("[^fn-{$aPrefix}-1]: First note.", $result->outputs()['index.md']);
            self::assertStringContainsString("[^fn-{$bPrefix}-1]: Second note.", $result->outputs()['index.md']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testExplicitPolicyReportsTheIncludeDepthLimit(): void
    {
        $root = $this->tempDirectory();
        file_put_contents($root . '/index.rst', ".. include:: first.rst.inc\n");
        file_put_contents($root . '/first.rst.inc', ".. include:: second.rst.inc\n");
        file_put_contents($root . '/second.rst.inc', "Never reached.\n");

        try {
            $result = new ProjectConverter()->convertDirectory(
                $root,
                Profile::sphinx(),
                new ConversionOptions(fileAccessPolicy: FileAccessPolicy::rootedAt($root, 1)),
            );

            self::assertStringContainsString('<!-- rst: directive include depth -->', $result->outputs()['index.md']);
            self::assertSame(['directive:include' => 1], $result->report->countsByConstruct());
            self::assertFalse($result->isComplete());
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testExplicitPolicyReportsParserErrorsFromAnIncludedFile(): void
    {
        $root = $this->tempDirectory();
        file_put_contents($root . '/index.rst', ".. include:: broken.rst.inc\n");
        file_put_contents($root . '/broken.rst.inc', "=======\nBroken\n------\n");

        try {
            $result = new ProjectConverter()->convertDirectory(
                $root,
                Profile::sphinx(),
                new ConversionOptions(fileAccessPolicy: FileAccessPolicy::rootedAt($root)),
            );

            self::assertStringContainsString('Broken', $result->outputs()['index.md']);
            self::assertSame(['directive:include' => 2], $result->report->countsByConstruct());
            self::assertFalse($result->isComplete());
            self::assertStringContainsString(
                'section/overline-underline-mismatch',
                $result->report->issues[0]->message,
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testExplicitPolicyDoesNotSilentlyIgnoreIncludeOptions(): void
    {
        $root = $this->tempDirectory();
        file_put_contents(
            $root . '/index.rst',
            ".. include:: shared.rst.inc\n"
            . "    :start-line: 2\n",
        );
        file_put_contents($root . '/shared.rst.inc', "first\nsecond\n");

        try {
            $result = new ProjectConverter()->convertDirectory(
                $root,
                Profile::sphinx(),
                new ConversionOptions(fileAccessPolicy: FileAccessPolicy::rootedAt($root)),
            );

            self::assertStringNotContainsString('second', $result->outputs()['index.md']);
            self::assertSame(['directive:include' => 1], $result->report->countsByConstruct());
            self::assertFalse($result->isLossless());
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testExplicitPolicyReportsAMissingIncludedFile(): void
    {
        $root = $this->tempDirectory();
        file_put_contents($root . '/index.rst', ".. include:: missing.rst.inc\n");

        try {
            $result = new ProjectConverter()->convertDirectory(
                $root,
                Profile::sphinx(),
                new ConversionOptions(fileAccessPolicy: FileAccessPolicy::rootedAt($root)),
            );

            self::assertStringContainsString('<!-- rst: directive include -->', $result->outputs()['index.md']);
            self::assertSame(['directive:include' => 1], $result->report->countsByConstruct());
            self::assertFalse($result->isLossless());
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testSourceMapPreservesLeadingSpacesAndUnicodePathComponents(): void
    {
        $result = new ProjectConverter()->convertSources([
            ' guides/écriture.rst' => "Écriture\n========\n",
        ]);

        self::assertSame(' guides/écriture.rst', $result->files[0]->sourcePath);
        self::assertSame(' guides/écriture.md', $result->files[0]->targetPath);
        self::assertArrayHasKey(' guides/écriture.md', $result->outputs());
        self::assertNotNull($result->file(' guides/écriture.rst'));
        self::assertNull($result->file('guides/écriture.rst'));
    }

    public function testDistinctPathsWithLeadingSpacesRemainDistinctInTheProjectMap(): void
    {
        $result = new ProjectConverter()->convertSources([
            ' guide/a.rst' => ".. _same:\n\nFirst\n=====\n",
            'guide/a.rst' => ".. _same:\n\nSecond\n======\n",
            'index.rst' => "Home\n====\n\nSee :ref:`same`.\n",
        ], Profile::sphinx());

        self::assertCount(3, $result->files);
        self::assertCount(1, $result->projectReferenceProblems);
        self::assertSame(
            'reference/ambiguous-project-target',
            $result->projectReferenceProblems->problems()[0]->code,
        );
    }

    public function testDirectoryDiscoveryPreservesLeadingSpacesAndUnicodeFilenames(): void
    {
        $root = $this->tempDirectory();
        file_put_contents($root . '/ étonné.rst', "Étonné\n======\n");

        try {
            $result = new ProjectConverter()->convertDirectory($root);

            self::assertSame([' étonné.md'], array_keys($result->outputs()));
            self::assertSame(' étonné.rst', $result->files[0]->sourcePath);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testDirectoryConversionSkipsSymbolicLinks(): void
    {
        $root = $this->tempDirectory();
        $outside = tempnam(sys_get_temp_dir(), 'alto-rst-outside-');
        self::assertIsString($outside);
        file_put_contents($outside, "Outside\n=======\n");
        $link = $root . '/outside.rst';

        if (!@symlink($outside, $link)) {
            unlink($outside);
            $this->removeDirectory($root);
            self::markTestSkipped('Symbolic links are unavailable.');
        }

        try {
            $result = new ProjectConverter()->convertDirectory($root);

            self::assertSame([], $result->files);
            self::assertSame([], $result->outputs());
        } finally {
            unlink($link);
            unlink($outside);
            $this->removeDirectory($root);
        }
    }

    public function testRejectsInvalidRootsAndUnsafeSourcePaths(): void
    {
        $converter = new ProjectConverter();

        try {
            $converter->convertDirectory('');
            self::fail('Expected an empty root to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Project root must not be empty.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not traverse its root');

        $converter->convertSources(['../escape.rst' => '']);
    }

    public function testRejectsMissingDirectories(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a readable directory');

        new ProjectConverter()->convertDirectory(sys_get_temp_dir() . '/alto-rst-missing-' . bin2hex(random_bytes(4)));
    }

    public function testRejectsAbsoluteNonRstAndDuplicateCanonicalPaths(): void
    {
        $converter = new ProjectConverter();
        $rejected = 0;

        foreach (
            [
                ['/absolute.rst' => ''],
                ['C:\\absolute.rst' => ''],
                ['readme.txt' => ''],
                ['./same.rst' => '', 'same.rst' => ''],
            ] as $sources
        ) {
            try {
                $converter->convertSources($sources);
                self::fail('Expected an unsafe source map to be rejected.');
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        self::assertSame(4, $rejected);
    }

    public function testRejectsNullBytesInSourcePaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain null bytes');

        new ProjectConverter()->convertSources(["bad\0path.rst" => '']);
    }

    private function tempDirectory(): string
    {
        $path = sys_get_temp_dir() . '/alto-rst-project-' . bin2hex(random_bytes(8));
        mkdir($path);

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($path);
    }
}
