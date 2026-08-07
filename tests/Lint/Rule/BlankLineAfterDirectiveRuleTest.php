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

use Alto\Rst\Lint\Rule\BlankLineAfterDirectiveRule;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlankLineAfterDirectiveRule::class)]
final class BlankLineAfterDirectiveRuleTest extends TestCase
{
    public function testCode(): void
    {
        self::assertSame('lint/blank-line-after-directive', new BlankLineAfterDirectiveRule()->code());
    }

    public function testBlankLineBeforeContentIsAccepted(): void
    {
        self::assertSame([], self::lint(".. note::\n\n    Content.\n"));
    }

    public function testContentDirectlyAfterTheMarkerIsReported(): void
    {
        $problems = self::lint(".. note::\n    Content.\n");

        self::assertCount(1, $problems);
        self::assertSame(ProblemSeverity::Info, $problems[0]->severity);
        self::assertSame('Add a blank line after the "note" directive before its content.', $problems[0]->message);
    }

    public function testOptionsThenBlankLineAreAccepted(): void
    {
        self::assertSame([], self::lint(".. code-block:: php\n    :linenos:\n\n    echo 1;\n"));
    }

    public function testContentDirectlyAfterOptionsIsReported(): void
    {
        $problems = self::lint(".. code-block:: php\n    :linenos:\n    echo 1;\n");

        self::assertCount(1, $problems);
        self::assertSame('Add a blank line after the "code-block" directive before its content.', $problems[0]->message);
    }

    public function testDirectiveWithoutBodyIsAccepted(): void
    {
        self::assertSame([], self::lint(".. versionadded:: 7.1\n\nParagraph after.\n"));
    }

    public function testNestedDirectivesAreSeen(): void
    {
        $problems = self::lint("Title\n=====\n\n.. tip::\n    No blank line.\n");

        self::assertCount(1, $problems);
    }

    public function testABodyOfOptionLookingLinesIsTreatedAsTheOptionBlock(): void
    {
        // ":a\: x" fails the parser's field syntax and becomes the body,
        // but still reads as an option to this rule; the scan then leaves
        // the directive span without reporting.
        self::assertSame([], self::lint(".. note::\n   :a\\: x\n\nTail.\n"));
    }

    /**
     * @return list<Problem>
     */
    private static function lint(string $rst): array
    {
        $document = Rst::symfony()->parse($rst)->document();
        $problems = new ProblemCollector();
        new BlankLineAfterDirectiveRule()->check($document, Source::fromString($rst), $problems);

        return $problems->report()->problems();
    }
}
