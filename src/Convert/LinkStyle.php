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
 * How links are written in the converted output.
 *
 * Preserve is the default because round-trip fidelity is the hard part of
 * translation: reStructuredText documentation is written overwhelmingly with
 * reference-style targets, and normalizing them to inline links rewrites
 * every paragraph a reviewer has to read.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum LinkStyle: string
{
    /**
     * Keep the style the source used.
     */
    case Preserve = 'preserve';

    /**
     * Always inline, definitions dropped.
     */
    case Inline = 'inline';

    /**
     * Always reference-style, definitions collected at the end.
     */
    case Reference = 'reference';
}
