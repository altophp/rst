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
 * One conformance fixture: an .rst input and its docutils pseudo-XML oracle.
 */
final readonly class ConformanceFixture
{
    public function __construct(
        public string $family,
        public string $name,
        public string $rstPath,
        public string $pseudoXmlPath,
    ) {}

    /**
     * Stable identifier, "family/name", usable as a data provider key.
     */
    public function id(): string
    {
        return $this->family . '/' . $this->name;
    }

    public function rst(): string
    {
        return $this->read($this->rstPath);
    }

    public function expectedPseudoXml(): string
    {
        return $this->read($this->pseudoXmlPath);
    }

    public function hasExpectedPseudoXml(): bool
    {
        return is_file($this->pseudoXmlPath);
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException(\sprintf('Unable to read fixture file %s', $path));
        }

        return $contents;
    }
}
