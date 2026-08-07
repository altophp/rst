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

namespace Alto\Rst\Lint\Rule;

use Alto\Rst\Lint\DocumentRule;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\Document;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;

/**
 * Checks that console examples use the "terminal" code block language
 * rather than "bash", "sh", "shell", or "console", per the Symfony
 * documentation standards.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CodeBlockTerminalRule implements DocumentRule
{
    private const array CODE_BLOCK_DIRECTIVES = ['code-block', 'code', 'sourcecode'];

    private const array CONSOLE_LANGUAGES = ['bash', 'sh', 'shell', 'console'];

    public function code(): string
    {
        return 'lint/code-block-terminal';
    }

    public function check(Document $document, ProblemCollector $problems): void
    {
        foreach ($document->descendants() as $node) {
            if (!$node instanceof Directive) {
                continue;
            }

            if (!\in_array(strtolower($node->name), self::CODE_BLOCK_DIRECTIVES, true)) {
                continue;
            }

            $language = $node->arguments[0] ?? null;

            if (null === $language || !\in_array(strtolower($language), self::CONSOLE_LANGUAGES, true)) {
                continue;
            }

            $problems->add(new Problem(
                ProblemSeverity::Info,
                $this->code(),
                sprintf('Use the "terminal" language for console examples instead of "%s".', $language),
                $node->span(),
            ));
        }
    }
}
