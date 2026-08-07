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

use Alto\Rst\Lint\Rule\ForbiddenDirectiveRule;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Rst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForbiddenDirectiveRule::class)]
final class ForbiddenDirectiveRuleTest extends TestCase
{
    public function testCode(): void
    {
        self::assertSame('lint/forbidden-directive', new ForbiddenDirectiveRule()->code());
    }

    public function testAllowedDirectivesAreAccepted(): void
    {
        self::assertSame([], self::lint(".. note::\n\n    Fine.\n"));
    }

    public function testCautionIsForbiddenWithReplacements(): void
    {
        $problems = self::lint(".. caution::\n\n    Watch out.\n");

        self::assertCount(1, $problems);
        self::assertSame(ProblemSeverity::Warning, $problems[0]->severity);
        self::assertSame('Directive "caution" is forbidden; use "warning" or "danger" instead.', $problems[0]->message);
    }

    public function testIndexIsForbiddenWithoutReplacements(): void
    {
        $problems = self::lint(".. index::\n   single: pair\n");

        self::assertCount(1, $problems);
        self::assertSame('Directive "index" is forbidden.', $problems[0]->message);
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $problems = self::lint(".. Caution::\n\n    Watch out.\n");

        self::assertCount(1, $problems);
    }

    public function testNestedDirectivesAreSeen(): void
    {
        $problems = self::lint("Title\n=====\n\n.. caution::\n\n    Watch out.\n");

        self::assertCount(1, $problems);
    }

    public function testCustomForbiddenSetReplacesTheDefault(): void
    {
        $rule = new ForbiddenDirectiveRule(['tip' => []]);

        self::assertSame([], self::lint(".. caution::\n\n    Fine now.\n", $rule));

        $problems = self::lint(".. tip::\n\n    Nope.\n", $rule);
        self::assertCount(1, $problems);
        self::assertSame('Directive "tip" is forbidden.', $problems[0]->message);
    }

    /**
     * @return list<Problem>
     */
    private static function lint(string $rst, ?ForbiddenDirectiveRule $rule = null): array
    {
        $document = Rst::symfony()->parse($rst)->document();
        $problems = new ProblemCollector();
        ($rule ?? new ForbiddenDirectiveRule())->check($document, $problems);

        return $problems->report()->problems();
    }
}
