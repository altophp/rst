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

namespace Alto\Rst\Tests\Lint\Rule;

use Alto\Rst\Lint\ExternalLinkDestination;
use Alto\Rst\Lint\LintConfig;
use Alto\Rst\Lint\Linter;
use Alto\Rst\Lint\Rule\BlankLineAfterAnchorRule;
use Alto\Rst\Lint\Rule\ForbiddenLinkDestinationRule;
use Alto\Rst\Lint\Rule\InvalidLinkDestinationRule;
use Alto\Rst\Lint\Rule\UnresolvedReferenceRule;
use Alto\Rst\Lint\Rule\UnusedExternalLinkDefinitionRule;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemReport;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnresolvedReferenceRule::class)]
#[CoversClass(UnusedExternalLinkDefinitionRule::class)]
#[CoversClass(ForbiddenLinkDestinationRule::class)]
#[CoversClass(InvalidLinkDestinationRule::class)]
#[CoversClass(BlankLineAfterAnchorRule::class)]
#[CoversClass(ExternalLinkDestination::class)]
final class ReferenceRulesTest extends TestCase
{
    public function testUnresolvedReferencesAreReportedAtByteSpans(): void
    {
        $rst = "Échec missing_.\n";
        $problem = self::problems($rst, Rst::docutils(), 'lint/unresolved-reference')[0];

        self::assertNotNull($problem->span);
        self::assertSame(strpos($rst, 'missing_'), $problem->span->start);
        self::assertSame(\strlen('missing_'), $problem->span->length);
    }

    public function testImplicitSectionTargetsAreResolved(): void
    {
        $rst = "Heading\n=======\n\nSee Heading_.\n";

        self::assertSame(
            [],
            self::problems($rst, Rst::docutils(), 'lint/unresolved-reference'),
        );
    }

    public function testDeferredSphinxProjectReferencesAreNotReported(): void
    {
        $rst = "See :ref:`other-label` and :doc:`other/page`.\n";

        self::assertSame(
            [],
            self::problems($rst, Rst::sphinx(), 'lint/unresolved-reference'),
        );
    }

    public function testOnlyUnusedExternalLinkDefinitionsAreReported(): void
    {
        $rst = ".. _anchor:\n\n"
            ."Heading\n=======\n\n"
            ."Use used_.\n\n"
            .".. _used: https://used.test/\n"
            .".. _unused: https://unused.test/\n";
        $problems = self::problems(
            $rst,
            Rst::docutils(),
            'lint/unused-external-link-definition',
        );

        self::assertCount(1, $problems);
        self::assertStringContainsString('"unused"', $problems[0]->message);
        self::assertSame(strpos($rst, '.. _unused:'), $problems[0]->span?->start);
    }

    public function testSimpleAdmonitionBodiesAreCoveredByReferenceRules(): void
    {
        $rst = ".. note::\n\n"
            ."    See used_ and `bad <javascript:go>`_.\n\n"
            .".. _used: https://ok.test\n";

        self::assertSame(
            [],
            self::problems($rst, Rst::docutils(), 'lint/unused-external-link-definition'),
        );

        $forbidden = self::problems(
            $rst,
            Rst::docutils(),
            'lint/forbidden-link-destination',
        );
        self::assertCount(1, $forbidden);
        self::assertSame(strpos($rst, '`bad'), $forbidden[0]->span?->start);
    }

    public function testUniformlyIndentedMultilineAdmonitionsAreCoveredByReferenceRules(): void
    {
        $rst = ".. note::\n\n"
            ."    First line.\n"
            ."    See used_ and `bad <javascript:go>`_.\n\n"
            .".. _used: https://ok.test\n";

        self::assertSame(
            [],
            self::problems($rst, Rst::docutils(), 'lint/unused-external-link-definition'),
        );

        $forbidden = self::problems(
            $rst,
            Rst::docutils(),
            'lint/forbidden-link-destination',
        );
        self::assertCount(1, $forbidden);
        self::assertSame(strpos($rst, '`bad'), $forbidden[0]->span?->start);
    }

    public function testOpaqueDirectiveBodySuppressesUnusedConclusion(): void
    {
        $rst = ".. raw:: text\n\n"
            ."    See used_.\n\n"
            .".. _used: https://ok.test\n";

        self::assertSame(
            [],
            self::problems($rst, Rst::docutils(), 'lint/unused-external-link-definition'),
        );
    }

    public function testForbiddenSchemesAreCheckedForDefinitionsAndEmbeddedLinks(): void
    {
        $rst = "Visit `inline <javascript:alert(1)>`_.\n\n"
            .".. _defined: data:text/html,test\n";
        $problems = self::problems(
            $rst,
            Rst::docutils(),
            'lint/forbidden-link-destination',
        );

        self::assertCount(2, $problems);
        self::assertSame(strpos($rst, '`inline'), $problems[0]->span?->start);
        self::assertSame(strpos($rst, '.. _defined:'), $problems[1]->span?->start);
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlsAreRejectedLocally(string $url): void
    {
        $rst = '.. _link: '.$url."\n";

        self::assertCount(
            1,
            self::problems($rst, Rst::docutils(), 'lint/invalid-link-destination'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'missing HTTP host' => ['https://'];
        yield 'missing scheme-relative host' => ['//'];
        yield 'empty custom scheme value' => ['custom:'];
        yield 'malformed percent escape' => ['https://example.test/%no'];
    }

    public function testValidUrlsNeedNoNetworkAccess(): void
    {
        $rst = ".. _absolute: https://host-that-does-not-exist.invalid/path?q=1#part\n"
            .".. _relative: ../guide/page.html#part\n"
            .".. _fragment: #part\n"
            .".. _mail: mailto:team@example.test\n";

        self::assertSame(
            [],
            self::problems($rst, Rst::docutils(), 'lint/invalid-link-destination'),
        );
    }

    public function testBlankLineIsRequiredAfterInternalAnchorOnly(): void
    {
        $rst = ".. _anchor:\n"
            ."Heading\n"
            ."=======\n\n"
            .".. _one: https://one.test/\n"
            .".. _two: https://two.test/\n";
        $problems = self::problems($rst, Rst::docutils(), 'lint/blank-line-after-anchor');

        self::assertCount(1, $problems);
        self::assertSame(strpos($rst, 'Heading'), $problems[0]->span?->start);
    }

    public function testContextRulesAreSkippedWithoutSource(): void
    {
        $result = Rst::docutils()->parse("Missing missing_.\n");
        $report = new Linter()->lint($result->document(), LintConfig::recommended());

        self::assertSame(
            [],
            array_values(array_filter(
                $report->problems(),
                static fn (Problem $problem): bool => 'lint/unresolved-reference' === $problem->code,
            )),
        );
    }

    /**
     * @return list<Problem>
     */
    private static function problems(string $rst, Rst $engine, string $code): array
    {
        $source = Source::fromString($rst);
        $result = $engine->parse($rst);
        $report = new Linter()->lint(
            $result->document(),
            LintConfig::recommended(),
            $source,
            $result->references(),
        );

        return self::withCode($report, $code);
    }

    /**
     * @return list<Problem>
     */
    private static function withCode(ProblemReport $report, string $code): array
    {
        return array_values(array_filter(
            $report->problems(),
            static fn (Problem $problem): bool => $code === $problem->code,
        ));
    }
}
