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

namespace Alto\Rst\Reference;

/**
 * Resolution is explicit so ambiguity is never mistaken for absence.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum ReferenceStatus: string
{
    case Resolved = 'resolved';
    case Unresolved = 'unresolved';
    case Ambiguous = 'ambiguous';
    case Circular = 'circular';
    case Deferred = 'deferred';
}
