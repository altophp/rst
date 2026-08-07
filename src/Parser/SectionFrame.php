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

namespace Alto\Rst\Parser;

use Alto\Rst\Node\Node;
use Alto\Rst\Node\Title;

/**
 * A section under construction while the document body is scanned. The
 * immutable Section node is built when the frame closes.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class SectionFrame
{
    /**
     * @var list<Node>
     */
    public array $children = [];

    public function __construct(
        public readonly int $level,
        public readonly Title $title,
        public readonly string $adornment,
        public readonly bool $hasOverline,
        public readonly int $start,
        public int $end,
        public readonly bool $styleAccepted = true,
    ) {
    }

    public function append(Node $node): void
    {
        $this->children[] = $node;
        $this->end = max($this->end, $node->span()->end());
    }
}
