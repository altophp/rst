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

namespace Alto\Rst\Convert\Markdown;

use Alto\Rst\Source\ByteSpan;

/**
 * A raw inline HTML tag, comment, processing instruction, declaration, or
 * CDATA section, kept verbatim.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MdInlineHtml extends MdNode
{
    public function __construct(
        ByteSpan $span,
        public string $content,
    ) {
        parent::__construct($span);
    }
}
