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
 * An ATX ("# Heading") or setext ("Heading" underlined) heading.
 *
 * Setext headings only ever produce level 1 ("=") or level 2 ("-").
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MdHeading extends MdContainerNode
{
    /**
     * @param list<MdNode> $children
     */
    public function __construct(
        ByteSpan $span,
        public int $level,
        public MdHeadingStyle $style,
        private array $children,
    ) {
        parent::__construct($span);
    }

    /**
     * @return list<MdNode>
     */
    public function children(): array
    {
        return $this->children;
    }
}
