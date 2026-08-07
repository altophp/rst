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

use Alto\Rst\Lint\Rule\CodeBlockTerminalRule;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Rst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CodeBlockTerminalRule::class)]
final class CodeBlockTerminalRuleTest extends TestCase
{
    public function testCode(): void
    {
        self::assertSame('lint/code-block-terminal', new CodeBlockTerminalRule()->code());
    }

    public function testTerminalIsAccepted(): void
    {
        self::assertSame([], self::lint(".. code-block:: terminal\n\n    $ composer install\n"));
    }

    public function testConsoleLanguagesAreReported(): void
    {
        foreach (['bash', 'sh', 'shell', 'console'] as $language) {
            $problems = self::lint(".. code-block:: {$language}\n\n    $ ls\n");

            self::assertCount(1, $problems, $language);
            self::assertSame(ProblemSeverity::Info, $problems[0]->severity);
            self::assertSame(
                sprintf('Use the "terminal" language for console examples instead of "%s".', $language),
                $problems[0]->message,
            );
        }
    }

    public function testNonCodeBlockDirectivesAreIgnored(): void
    {
        self::assertSame([], self::lint(".. note:: bash\n\n    text\n"));
    }

    public function testMissingLanguageIsIgnored(): void
    {
        self::assertSame([], self::lint(".. code-block::\n\n    text\n"));
    }

    /**
     * @return list<Problem>
     */
    private static function lint(string $rst): array
    {
        $document = Rst::symfony()->parse($rst)->document();
        $problems = new ProblemCollector();
        new CodeBlockTerminalRule()->check($document, $problems);

        return $problems->report()->problems();
    }
}
