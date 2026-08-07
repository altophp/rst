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

use Alto\Rst\Convert\Markdown\MdDocument;
use Alto\Rst\Convert\Writer\RstWriter;

/**
 * Translates a Markdown model tree to reStructuredText.
 *
 * The inverse of RstToMarkdown: headings take the configured adornment
 * order, fenced code becomes a code-block directive, GitHub alerts become
 * admonitions, reference links become RST targets, and pipe tables become
 * simple tables. Constructs without an RST equivalent degrade
 * conservatively and land in the report; conversion never throws.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MarkdownToRst
{
    public function convert(MdDocument $document, ?ConversionOptions $options = null): ConversionResult
    {
        return new RstWriter($options ?? new ConversionOptions())->write($document);
    }
}
