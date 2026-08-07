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
use Alto\Rst\Convert\ConversionResult;
use Alto\Rst\Convert\IssueKind;
use Alto\Rst\Convert\Markdown\MarkdownReader;
use Alto\Rst\Convert\MarkdownToRst;
use Alto\Rst\Convert\RstToMarkdown;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Source\Source;
use Alto\Rst\Tests\Convert\Support\RstShape;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\TestCase;

/**
 * The production workload: every Symfony UX documentation file must
 * convert to Markdown without a single unsupported construct. Lossy and
 * approximated entries are the measured conversion debt; their aggregate
 * counts are printed for the record.
 *
 * The corpus lives outside the repository. Point ALTO_RST_UX_CORPUS at a
 * Symfony UX checkout to run these; the tests skip when it is unset or the
 * checkout is absent.
 */
#[CoversNamespace('Alto\\Rst\\Convert')]
final class UxCorpusTest extends TestCase
{
    private const string CORPUS_ENV = 'ALTO_RST_UX_CORPUS';

    private const string CORPUS_GLOB = '/src/*/doc/*.rst';

    private const string BENCHMARK_PATH = '/src/LiveComponent/doc/index.rst';

    private const string BENCHMARK_REVISION = '0e423fd53442e6f02fd070f7e68888bf74ffbd51';

    private const string BENCHMARK_SHA256 = '2c5dafeccd27d0703b432d57c2afe040485c49e740473f8da770b19c0011376b';

    private const string DRIFT_FIXTURE = __DIR__.'/../fixtures/convert/round-trip-drift.rst';

    private const string DRIFT_FIXTURE_SHA256 = '45a2e49da6d96df65a982807f7bd511fe7bad7f4190cdc679575d4f86e13a772';

    private static function corpusRoot(): ?string
    {
        $root = getenv(self::CORPUS_ENV);

        return \is_string($root) && '' !== $root ? rtrim($root, '/') : null;
    }

    /**
     * @return list<string>
     */
    private static function corpusFiles(): array
    {
        $root = self::corpusRoot();
        if (null === $root) {
            return [];
        }

        $files = glob($root.self::CORPUS_GLOB);

        return false === $files ? [] : $files;
    }

    private static function convertFile(string $file): ConversionResult
    {
        $rst = file_get_contents($file);
        self::assertNotFalse($rst);

        $source = Source::fromString($rst);
        $document = new BlockParser()->parse($source)->document();

        return new RstToMarkdown()->convert($document, $source, Profile::symfony(), ConversionOptions::symfony());
    }

    public function testEveryCorpusFileConvertsWithoutUnsupportedConstructs(): void
    {
        $files = self::corpusFiles();

        if ([] === $files) {
            self::markTestSkipped(\sprintf('Set %s to a Symfony UX checkout to run this test.', self::CORPUS_ENV));
        }

        $totalsByKind = [];
        $totalsByConstruct = [];
        $unsupported = [];

        foreach ($files as $file) {
            $result = self::convertFile($file);
            $name = basename(\dirname($file, 2)).'/'.basename($file);

            self::assertNotSame('', $result->output);

            foreach ($result->report->issues as $issue) {
                $totalsByKind[$issue->kind->value] = ($totalsByKind[$issue->kind->value] ?? 0) + 1;
                $totalsByConstruct[$issue->kind->value.' '.$issue->construct] = ($totalsByConstruct[$issue->kind->value.' '.$issue->construct] ?? 0) + 1;

                if (IssueKind::Unsupported === $issue->kind) {
                    $unsupported[] = sprintf('%s: %s (%s)', $name, $issue->construct, $issue->message);
                }
            }
        }

        ksort($totalsByKind);
        ksort($totalsByConstruct);

        $summary = sprintf(
            "UX corpus: %d files converted.\nIssues by kind: %s\nIssues by construct:\n%s\n",
            \count($files),
            [] === $totalsByKind ? 'none' : json_encode($totalsByKind),
            [] === $totalsByConstruct ? '  none' : implode("\n", array_map(
                static fn (string $key, int $count): string => sprintf('  %-45s %d', $key, $count),
                array_keys($totalsByConstruct),
                array_values($totalsByConstruct),
            )),
        );

        fwrite(\STDERR, "\n".$summary);

        self::assertSame([], $unsupported, "Unsupported constructs found:\n".implode("\n", $unsupported));
    }

    public function testTheLiveComponentBenchmarkSurvivesARoundTrip(): void
    {
        $root = self::corpusRoot();
        $benchmark = null === $root ? null : $root.self::BENCHMARK_PATH;

        if (null === $benchmark || !is_file($benchmark)) {
            self::markTestSkipped(\sprintf('Set %s to a Symfony UX checkout to run this test.', self::CORPUS_ENV));
        }

        $rst = file_get_contents($benchmark);
        self::assertNotFalse($rst);

        if (self::BENCHMARK_SHA256 !== hash('sha256', $rst)) {
            self::markTestSkipped(sprintf(
                'LiveComponent benchmark differs from pinned Symfony UX revision %s.',
                self::BENCHMARK_REVISION,
            ));
        }

        $source = Source::fromString($rst);
        $document = new BlockParser()->parse($source, Profile::symfony())->document();
        $forward = new RstToMarkdown()->convert($document, $source, Profile::symfony(), ConversionOptions::symfony());

        $mdDocument = new MarkdownReader()->read($forward->output);
        $reverse = new MarkdownToRst()->convert($mdDocument, ConversionOptions::symfony());

        $original = RstShape::of($rst);
        $returned = RstShape::of($reverse->output);

        $drift = self::drift($original, $returned, '');

        fwrite(\STDERR, sprintf(
            "\nLiveComponent round trip: %d shape drift(s).\n%s",
            \count($drift),
            [] === $drift ? '' : implode("\n", \array_slice($drift, 0, 20))."\n",
        ));

        self::assertSame(
            [
                'role:ref' => 15,
                'definition-list' => 1,
                'target:internal' => 1,
            ],
            $forward->report->countsByConstruct(),
            'The forward conversion debt changed. Classify the new drift before changing this budget.',
        );
        self::assertSame(
            [
                'md:inline-html' => 2,
                'md:nested-markup' => 2,
                'md:code-span' => 1,
            ],
            $reverse->report->countsByConstruct(),
            'The reverse conversion debt changed. Classify the new drift before changing this budget.',
        );
        self::assertCount(
            254,
            $drift,
            'The semantic leaf drift changed. Inspect alphabetic list and other paths before changing this budget.',
        );
        self::assertSame(
            '47b96aa29e285da3631ee8c2627f296ef89314208360b8d0fc3c91a3da20cdb0',
            hash('sha256', json_encode($drift, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)),
            'The semantic leaf drift changed without changing its count. Inspect the paths before changing this snapshot.',
        );

        // The round trip must preserve the document skeleton exactly:
        // section hierarchy and code block count.
        self::assertSame(
            self::skeleton($original['body']),
            self::skeleton($returned['body']),
        );
    }

    public function testPinnedRoundTripDriftGate(): void
    {
        $rst = file_get_contents(self::DRIFT_FIXTURE);
        self::assertNotFalse($rst);
        self::assertSame(self::DRIFT_FIXTURE_SHA256, hash('sha256', $rst));

        $source = Source::fromString($rst);
        $document = new BlockParser()->parse($source, Profile::symfony())->document();
        $forward = new RstToMarkdown()->convert($document, $source, Profile::symfony(), ConversionOptions::symfony());

        $mdDocument = new MarkdownReader()->read($forward->output);
        $reverse = new MarkdownToRst()->convert($mdDocument, ConversionOptions::symfony());

        $original = RstShape::of($rst);
        $returned = RstShape::of($reverse->output);
        $drift = self::drift($original, $returned, '');

        self::assertSame(
            [
                'role:ref' => 1,
                'target:internal' => 1,
            ],
            $forward->report->countsByConstruct(),
        );
        self::assertSame(['md:inline-html' => 2], $reverse->report->countsByConstruct());
        self::assertCount(5, $drift);
        self::assertSame(
            'e2a147334363385c7234b9891968f24ad0a7d06cfd6e8ea0185a6275780dd0be',
            hash('sha256', json_encode($drift, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)),
        );
        self::assertSame(
            self::skeleton($original['body']),
            self::skeleton($returned['body']),
        );
    }

    /**
     * Flattens a shape to its section-and-code skeleton.
     *
     * @return list<string>
     */
    private static function skeleton(mixed $shape): array
    {
        $out = [];

        if (!\is_array($shape)) {
            return $out;
        }

        if (($shape[0] ?? null) === 'section' && \is_int($shape[1] ?? null) && \is_string($shape[2] ?? null)) {
            $out[] = 'section '.$shape[1].' '.$shape[2];
        }

        if (($shape[0] ?? null) === 'directive' && \is_string($shape[1] ?? null)) {
            $out[] = 'directive '.$shape[1];
        }

        if (($shape[0] ?? null) === 'literal') {
            $out[] = 'literal';
        }

        foreach ($shape as $value) {
            if (\is_array($value)) {
                $out = [...$out, ...self::skeleton($value)];
            }
        }

        return $out;
    }

    /**
     * Reports the paths where two shapes differ.
     *
     * @return list<string>
     */
    private static function drift(mixed $left, mixed $right, string $path): array
    {
        if ($left === $right) {
            return [];
        }

        if (!\is_array($left) || !\is_array($right)) {
            return [sprintf('%s: %s != %s', '' === $path ? '/' : $path, self::describe($left), self::describe($right))];
        }

        $drift = [];
        $keys = array_unique([...array_keys($left), ...array_keys($right)]);

        foreach ($keys as $key) {
            if (!\array_key_exists($key, $left) || !\array_key_exists($key, $right)) {
                $drift[] = sprintf('%s/%s: only on one side', $path, $key);

                continue;
            }

            $drift = [...$drift, ...self::drift($left[$key], $right[$key], $path.'/'.$key)];
        }

        return $drift;
    }

    private static function describe(mixed $value): string
    {
        $encoded = json_encode($value);

        return false === $encoded ? get_debug_type($value) : substr($encoded, 0, 80);
    }
}
