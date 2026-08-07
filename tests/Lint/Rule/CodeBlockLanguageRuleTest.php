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

use Alto\Rst\Lint\Rule\CodeBlockLanguageRule;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Rst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CodeBlockLanguageRule::class)]
final class CodeBlockLanguageRuleTest extends TestCase
{
    public function testCode(): void
    {
        self::assertSame('lint/code-block-language', new CodeBlockLanguageRule()->code());
    }

    public function testKnownLanguagesAreAccepted(): void
    {
        foreach (['php', 'yaml', 'html+twig', 'terminal', 'diff'] as $language) {
            self::assertSame([], self::lint(".. code-block:: {$language}\n\n    content\n"), $language);
        }
    }

    public function testUnknownLanguageIsReported(): void
    {
        $problems = self::lint(".. code-block:: klingon\n\n    nuqneH\n");

        self::assertCount(1, $problems);
        self::assertSame(ProblemSeverity::Warning, $problems[0]->severity);
        self::assertSame('Unknown code block language "klingon".', $problems[0]->message);
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        self::assertSame([], self::lint(".. code-block:: PHP\n\n    echo 1;\n"));
    }

    public function testMissingLanguageIsAccepted(): void
    {
        self::assertSame([], self::lint(".. code-block::\n\n    text\n"));
    }

    public function testOtherDirectivesAreIgnored(): void
    {
        self::assertSame([], self::lint(".. note:: klingon\n\n    text\n"));
    }

    public function testCustomLanguageSetReplacesTheDefault(): void
    {
        $rule = new CodeBlockLanguageRule(['klingon']);

        self::assertSame([], self::lint(".. code-block:: klingon\n\n    nuqneH\n", $rule));
        self::assertCount(1, self::lint(".. code-block:: php\n\n    echo 1;\n", $rule));
    }

    /**
     * @return list<Problem>
     */
    private static function lint(string $rst, ?CodeBlockLanguageRule $rule = null): array
    {
        $document = Rst::symfony()->parse($rst)->document();
        $problems = new ProblemCollector();
        ($rule ?? new CodeBlockLanguageRule())->check($document, $problems);

        return $problems->report()->problems();
    }
}
