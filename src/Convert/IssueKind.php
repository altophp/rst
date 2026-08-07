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
 * How faithfully a construct survived conversion.
 *
 * Unsupported breaks completeness because no supported target equivalent was
 * emitted. A visible placeholder may still remain. Lossy breaks losslessness
 * because output exists but information was discarded. Approximated remains
 * complete and lossless, but prevents an exact status.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum IssueKind: string
{
    /** No supported target equivalent was emitted; a placeholder may remain. */
    case Unsupported = 'unsupported';

    /** Something was emitted, but information was dropped. */
    case Lossy = 'lossy';

    /** Something equivalent enough was emitted in a neighbouring construct. */
    case Approximated = 'approximated';
}
