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

namespace Alto\Rst\Tests\Support;

/**
 * Enumerates the docutils conformance corpus under tests/fixtures/conformance.
 *
 * The corpus layout is one directory per construct family; each fixture is a
 * <name>.rst input next to a <name>.pseudoxml docutils oracle output. See
 * tests/fixtures/conformance/README.md for the format and regeneration steps.
 */
final readonly class ConformanceCorpus
{
    private function __construct(
        public string $directory,
    ) {
    }

    public static function default(): self
    {
        return self::fromDirectory(\dirname(__DIR__).'/fixtures/conformance');
    }

    public static function fromDirectory(string $directory): self
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Conformance corpus directory not found: %s', $directory));
        }

        return new self($directory);
    }

    /**
     * The docutils version the committed oracle outputs were generated with.
     */
    public function docutilsVersion(): string
    {
        $version = @file_get_contents($this->directory.'/VERSION');
        if (false === $version) {
            throw new \RuntimeException(\sprintf('Missing VERSION file in %s', $this->directory));
        }

        return trim($version);
    }

    /**
     * @return list<string> family directory names, sorted
     */
    public function families(): array
    {
        $entries = @scandir($this->directory, \SCANDIR_SORT_ASCENDING);
        if (false === $entries) {
            throw new \RuntimeException(\sprintf('Unable to list %s', $this->directory));
        }

        $families = [];
        foreach ($entries as $entry) {
            if ('.' !== $entry[0] && is_dir($this->directory.'/'.$entry)) {
                $families[] = $entry;
            }
        }

        return $families;
    }

    /**
     * @param string|null $family limit to one family, or null for the whole corpus
     *
     * @return list<ConformanceFixture> sorted by family, then fixture name
     */
    public function fixtures(?string $family = null): array
    {
        $families = null === $family ? $this->families() : [$family];

        $fixtures = [];
        foreach ($families as $familyName) {
            foreach ($this->rstFilesIn($familyName) as $rstPath) {
                $name = basename($rstPath, '.rst');
                $fixtures[] = new ConformanceFixture(
                    family: $familyName,
                    name: $name,
                    rstPath: $rstPath,
                    pseudoXmlPath: $this->directory.'/'.$familyName.'/'.$name.'.pseudoxml',
                );
            }
        }

        return $fixtures;
    }

    /**
     * @return list<string>
     */
    private function rstFilesIn(string $family): array
    {
        $directory = $this->directory.'/'.$family;
        if (!is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Unknown conformance family "%s" in %s', $family, $this->directory));
        }

        $files = glob($directory.'/*.rst');
        if (false === $files) {
            throw new \RuntimeException(\sprintf('Unable to list fixtures in %s', $directory));
        }
        sort($files, \SORT_STRING);

        return $files;
    }
}
