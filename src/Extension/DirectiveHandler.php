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
use Alto\Rst\Node\Directive;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Render\HtmlPolicy;
use Alto\Rst\Source\Source;

/**
 * Executable HTML and Markdown behavior for one directive name.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface DirectiveHandler
{
    public function name(): string;

    public function renderHtml(
        Directive $directive,
        Source $source,
        Profile $profile,
        HtmlPolicy $htmlPolicy,
        DirectiveRenderContext $context,
    ): string;

    public function convertToMarkdown(
        Directive $directive,
        Source $source,
        Profile $profile,
        ConversionOptions $options,
        DirectiveConversionContext $context,
    ): ConversionResult;
}
