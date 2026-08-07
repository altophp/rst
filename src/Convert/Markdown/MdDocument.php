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
 * The root of the Markdown model tree.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MdDocument extends MdContainerNode
{
    /**
     * @param list<MdNode>                    $children
     * @param list<MdLinkReferenceDefinition> $linkReferenceDefinitions
     */
    public function __construct(
        ByteSpan $span,
        private array $children,
        public array $linkReferenceDefinitions = [],
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
