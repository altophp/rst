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

use Alto\Rst\Lint\Rule\VersionDirectiveVersionRule;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Rst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VersionDirectiveVersionRule::class)]
final class VersionDirectiveVersionRuleTest extends TestCase
{
    public function testCode(): void
    {
        self::assertSame('lint/version-directive-version', new VersionDirectiveVersionRule()->code());
    }

    public function testVersionedDirectivesAreAccepted(): void
    {
        self::assertSame([], self::lint(".. versionadded:: 7.1\n\n    Added.\n"));
        self::assertSame([], self::lint(".. deprecated:: 7.2\n\n    Gone soon.\n"));
        self::assertSame([], self::lint(".. versionchanged:: 7.1.3\n\n    Changed.\n"));
    }

    public function testMissingVersionIsReported(): void
    {
        $problems = self::lint(".. versionadded::\n\n    Added.\n");

        self::assertCount(1, $problems);
        self::assertSame(ProblemSeverity::Warning, $problems[0]->severity);
        self::assertSame('The "versionadded" directive must have a version argument.', $problems[0]->message);
    }

    public function testMissingVersionOnDeprecatedIsReported(): void
    {
        $problems = self::lint(".. deprecated::\n\n    Gone soon.\n");

        self::assertCount(1, $problems);
        self::assertSame('The "deprecated" directive must have a version argument.', $problems[0]->message);
    }

    public function testNonVersionArgumentIsReported(): void
    {
        $problems = self::lint(".. versionadded:: soon\n\n    Added.\n");

        self::assertCount(1, $problems);
        self::assertSame('Version "soon" of the "versionadded" directive is not a version number like "7.1".', $problems[0]->message);
    }

    public function testMajorOnlyVersionIsReported(): void
    {
        self::assertCount(1, self::lint(".. versionadded:: 7\n\n    Added.\n"));
    }

    public function testOtherDirectivesAreIgnored(): void
    {
        self::assertSame([], self::lint(".. note::\n\n    No version needed.\n"));
    }

    /**
     * @return list<Problem>
     */
    private static function lint(string $rst): array
    {
        $document = Rst::symfony()->parse($rst)->document();
        $problems = new ProblemCollector();
        new VersionDirectiveVersionRule()->check($document, $problems);

        return $problems->report()->problems();
    }
}
