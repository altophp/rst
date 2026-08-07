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
 * Per-column alignment from a GFM table delimiter row.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum MdTableAlignment
{
    /** No colon in the delimiter cell. */
    case None;

    /** ":---" */
    case Left;

    /** "---:" */
    case Right;

    /** ":---:" */
    case Center;
}
