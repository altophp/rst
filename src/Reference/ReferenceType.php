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
 * The language-level reference families resolved by the graph.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum ReferenceType: string
{
    case Hyperlink = 'hyperlink';
    case Footnote = 'footnote';
    case Citation = 'citation';
    case Substitution = 'substitution';
    case SphinxRef = 'sphinx-ref';
    case SphinxDoc = 'sphinx-doc';
}
