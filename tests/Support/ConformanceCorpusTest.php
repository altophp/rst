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

use PHPUnit\Framework\TestCase;

final class ConformanceCorpusTest extends TestCase
{
    public function testDefaultCorpusEnumeratesFamilies(): void
    {
        $corpus = ConformanceCorpus::default();

        $families = $corpus->families();

        self::assertNotSame([], $families);
        self::assertContains('sections', $families);
        self::assertContains('inline', $families);
        self::assertSame($families, array_values(array_unique($families)));
    }

    public function testEveryFixtureHasAnOracleOutput(): void
    {
        $fixtures = ConformanceCorpus::default()->fixtures();

        self::assertNotSame([], $fixtures);
        foreach ($fixtures as $fixture) {
            self::assertTrue($fixture->hasExpectedPseudoXml(), \sprintf('Missing pseudo-XML oracle for %s', $fixture->id()));
            self::assertStringStartsWith('<document', $fixture->expectedPseudoXml());
        }
    }

    public function testFixtureInputsUseUnixLineEndingsAndEndWithNewline(): void
    {
        foreach (ConformanceCorpus::default()->fixtures() as $fixture) {
            $rst = $fixture->rst();

            self::assertStringNotContainsString("\r", $rst, \sprintf('%s must use "\n" endings', $fixture->id()));
            self::assertStringEndsWith("\n", $rst, \sprintf('%s must end with a newline', $fixture->id()));
        }
    }

    public function testFixtureIdsAreUnique(): void
    {
        $ids = array_map(
            static fn (ConformanceFixture $fixture): string => $fixture->id(),
            ConformanceCorpus::default()->fixtures(),
        );

        self::assertSame($ids, array_values(array_unique($ids)));
    }

    public function testFixturesCanBeLimitedToOneFamily(): void
    {
        $corpus = ConformanceCorpus::default();

        $sections = $corpus->fixtures('sections');

        self::assertNotSame([], $sections);
        foreach ($sections as $fixture) {
            self::assertSame('sections', $fixture->family);
        }
    }

    public function testUnknownFamilyIsRejected(): void
    {
        $corpus = ConformanceCorpus::default();

        $this->expectException(\RuntimeException::class);
        $corpus->fixtures('no-such-family');
    }

    public function testDocutilsVersionIsPinned(): void
    {
        $version = ConformanceCorpus::default()->docutilsVersion();

        self::assertMatchesRegularExpression('/^\d+\.\d+(\.\d+)?$/', $version);
    }

    public function testMissingDirectoryIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        ConformanceCorpus::fromDirectory(__DIR__.'/does-not-exist');
    }
}
