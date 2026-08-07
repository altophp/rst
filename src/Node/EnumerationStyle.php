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
 * The numbering style of an enumerated list. Values mirror the docutils
 * enumtype names. Auto-enumerators ("#") resolve to the style of the
 * list they continue, arabic by default.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum EnumerationStyle: string
{
    case Arabic = 'arabic';
    case LowerAlpha = 'loweralpha';
    case UpperAlpha = 'upperalpha';
    case LowerRoman = 'lowerroman';
    case UpperRoman = 'upperroman';
}
