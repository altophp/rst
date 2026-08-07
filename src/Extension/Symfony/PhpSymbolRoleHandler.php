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

namespace Alto\Rst\Extension\Symfony;

use Alto\Rst\Convert\ConversionIssue;
use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\ConversionReport;
use Alto\Rst\Convert\ConversionResult;
use Alto\Rst\Extension\RoleHandler;
use Alto\Rst\Node\Inline\InterpretedText;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Render\HtmlPolicy;
use Alto\Rst\Source\Source;

/**
 * Symfony's PHP symbol roles render as code. An explicit title remains
 * visible, while Markdown reports that its target could not be retained.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class PhpSymbolRoleHandler implements RoleHandler
{
    public function __construct(
        private string $roleName,
    ) {
    }

    public function name(): string
    {
        return $this->roleName;
    }

    public function renderHtml(
        InterpretedText $role,
        Source $source,
        Profile $profile,
        HtmlPolicy $htmlPolicy,
    ): string {
        [$title] = self::roleParts($role->text);

        return '<code>'.htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code>';
    }

    public function convertToMarkdown(
        InterpretedText $role,
        Source $source,
        Profile $profile,
        ConversionOptions $options,
    ): ConversionResult {
        [$title, $target] = self::roleParts($role->text);
        $issues = [];

        if (null !== $target) {
            $issues[] = ConversionIssue::lossy(
                'role:'.$this->roleName,
                sprintf(
                    'PHP symbol target "%s" was dropped; visible title "%s" was preserved.',
                    $target,
                    $title,
                ),
                $role->span(),
            );
        }

        return new ConversionResult(self::codeSpan($title), new ConversionReport($issues));
    }

    /**
     * @return array{string, ?string}
     */
    private static function roleParts(string $text): array
    {
        if (1 !== preg_match('/^(.*\S)\s+<([^<>]+)>$/s', $text, $matches)) {
            return [$text, null];
        }

        return [trim($matches[1]), trim($matches[2])];
    }

    private static function codeSpan(string $text): string
    {
        $longest = 0;

        if (false !== preg_match_all('/`+/', $text, $matches)) {
            foreach ($matches[0] as $run) {
                $longest = max($longest, \strlen($run));
            }
        }

        $delimiter = str_repeat('`', $longest + 1);
        $pad = str_starts_with($text, '`') || str_ends_with($text, '`')
            || str_starts_with($text, ' ') || str_ends_with($text, ' ') ? ' ' : '';

        return $delimiter.$pad.$text.$pad.$delimiter;
    }
}
