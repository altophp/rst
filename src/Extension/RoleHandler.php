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

namespace Alto\Rst\Extension;

use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\ConversionResult;
use Alto\Rst\Node\Inline\InterpretedText;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Render\HtmlPolicy;
use Alto\Rst\Source\Source;

/**
 * Executable HTML and Markdown behavior for one canonical role name.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface RoleHandler
{
    public function name(): string;

    public function renderHtml(
        InterpretedText $role,
        Source $source,
        Profile $profile,
        HtmlPolicy $htmlPolicy,
    ): string;

    public function convertToMarkdown(
        InterpretedText $role,
        Source $source,
        Profile $profile,
        ConversionOptions $options,
    ): ConversionResult;
}
