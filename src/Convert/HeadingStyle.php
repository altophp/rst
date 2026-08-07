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
 * How Markdown headings are written.
 *
 * Setext only expresses two levels, so a converter emitting Setext falls back
 * to ATX below level 2 rather than flattening the hierarchy.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum HeadingStyle: string
{
    case Atx = 'atx';
    case Setext = 'setext';
}
