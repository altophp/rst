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
 * Standard docutils replacement families supported by the reference graph.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum SubstitutionKind: string
{
    case Replace = 'replace';
    case Unicode = 'unicode';
    case Image = 'image';
}
