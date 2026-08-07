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
 * "![alt](url \"title\")" or "![alt][label]".
 *
 * $children holds the parsed inline content of the alt text; a renderer
 * that needs a plain string flattens it. See MdLink for the resolution
 * rule shared by both inline and reference styles.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MdImage extends MdContainerNode
{
    /**
     * @param list<MdNode> $children
     */
    public function __construct(
        ByteSpan $span,
        private array $children,
        public string $url,
        public ?string $title,
        public MdLinkStyle $style,
        public ?string $referenceLabel = null,
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
