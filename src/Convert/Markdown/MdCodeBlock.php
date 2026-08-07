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
 * A fenced ("```" or "~~~") or indented (four-column) code block.
 *
 * $content is verbatim, never inline-parsed. $fenceChar and $fenceLength are
 * null for an indented block; $infoString is null when the fence carried no
 * info string, or always null for an indented block.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MdCodeBlock extends MdNode
{
    public function __construct(
        ByteSpan $span,
        public MdCodeBlockStyle $style,
        public string $content,
        public ?string $infoString = null,
        public ?string $fenceChar = null,
        public ?int $fenceLength = null,
    ) {
        parent::__construct($span);
    }
}
