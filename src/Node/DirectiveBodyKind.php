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
 * How a directive body participates in the document tree.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum DirectiveBodyKind: string
{
    /**
     * The directive does not accept body content.
     */
    case None = 'none';

    /**
     * The body is reStructuredText block content with typed child nodes.
     */
    case Blocks = 'blocks';

    /**
     * The body is literal data, such as source code or CSV rows.
     */
    case Literal = 'literal';

    /**
     * The body has directive-specific syntax not represented by the ROM.
     */
    case Opaque = 'opaque';
}
