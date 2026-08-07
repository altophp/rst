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

namespace Alto\Rst\Convert\Markdown;

/**
 * How a code block was written in the Markdown source.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum MdCodeBlockStyle
{
    /** Fenced with a run of "`" or "~". */
    case Fenced;

    /** Every line indented at least four columns. */
    case Indented;
}
