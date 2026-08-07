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

namespace Alto\Rst\Node;

/**
 * The source syntax a table was written in. Simple tables draw "=" borders
 * over space-separated columns; grid tables draw a full "+-|" box.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum TableStyle: string
{
    case Simple = 'simple';
    case Grid = 'grid';
}
