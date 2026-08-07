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

namespace Alto\Rst\Convert;

/**
 * How reStructuredText admonitions land in Markdown.
 *
 * GitHub alerts are the closest equivalent for the first production target
 * (the Symfony UX documentation, rendered on GitHub), but they only cover
 * five names; anything outside that set degrades to a plain blockquote with
 * a bold label, and the converter records the approximation.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum AdmonitionStyle: string
{
    /** "> **Note**" followed by the body. */
    case Blockquote = 'blockquote';

    /** "> [!NOTE]" followed by the body: rendered specially by GitHub. */
    case GithubAlert = 'github-alert';

    /**
     * The GitHub alert names, keyed by their lowercase reStructuredText
     * directive name.
     *
     * @return array<string, string>
     */
    public static function githubAlertNames(): array
    {
        return [
            'note' => 'NOTE',
            'tip' => 'TIP',
            'important' => 'IMPORTANT',
            'warning' => 'WARNING',
            'caution' => 'CAUTION',
        ];
    }
}
