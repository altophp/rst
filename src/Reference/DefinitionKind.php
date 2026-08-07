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
 * The definition families indexed by the graph.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum DefinitionKind: string
{
    case Hyperlink = 'hyperlink';
    case Section = 'section';
    case InlineTarget = 'inline-target';
    case Footnote = 'footnote';
    case Citation = 'citation';
    case Substitution = 'substitution';
}
