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
 * The highest-fidelity action required after a conversion.
 *
 * Status precedence is Blocked, Review, Tracked, then Exact.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum ConversionStatus: string
{
    /**
     * At least one construct produced no supported target equivalent.
     */
    case Blocked = 'blocked';

    /**
     * Output exists, but at least one mapping discarded information.
     */
    case Review = 'review';

    /**
     * Only intentional nearby target mappings were recorded.
     */
    case Tracked = 'tracked';

    /**
     * No conversion issue was recorded.
     */
    case Exact = 'exact';
}
