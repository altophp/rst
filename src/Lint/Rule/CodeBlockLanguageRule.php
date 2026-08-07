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
 * Checks that every code block directive argument names a known language.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CodeBlockLanguageRule implements DocumentRule
{
    private const array CODE_BLOCK_DIRECTIVES = ['code-block', 'code', 'sourcecode'];

    /**
     * Languages the Symfony documentation and common Sphinx setups use.
     *
     * @var list<string>
     */
    private const array KNOWN_LANGUAGES = [
        'bash',
        'console',
        'css',
        'diff',
        'env',
        'html',
        'html+php',
        'html+twig',
        'ini',
        'javascript',
        'json',
        'jsx',
        'markdown',
        'php',
        'php-annotations',
        'php-attributes',
        'php-standalone',
        'php-symfony',
        'python',
        'rst',
        'sh',
        'shell',
        'sql',
        'terminal',
        'text',
        'twig',
        'typescript',
        'xml',
        'yaml',
    ];

    /**
     * @var array<string, true>
     */
    private array $languages;

    /**
     * @param list<string>|null $languages accepted language names; null keeps
     *                                     the built-in set
     */
    public function __construct(?array $languages = null)
    {
        $lookup = [];

        foreach ($languages ?? self::KNOWN_LANGUAGES as $language) {
            $lookup[strtolower($language)] = true;
        }

        $this->languages = $lookup;
    }

    public function code(): string
    {
        return 'lint/code-block-language';
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

            if (null === $language || isset($this->languages[strtolower($language)])) {
                continue;
            }

            $problems->add(new Problem(
                ProblemSeverity::Warning,
                $this->code(),
                sprintf('Unknown code block language "%s".', $language),
                $node->span(),
            ));
        }
    }
}
