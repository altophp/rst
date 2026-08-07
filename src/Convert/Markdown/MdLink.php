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
 * "[text](url \"title\")" or "[text][label]".
 *
 * $url and $title are always resolved: for a reference-style link, they
 * come from the matching MdLinkReferenceDefinition. A reference that does
 * not resolve to a definition never produces an MdLink; it degrades to
 * literal text, per MarkdownReader::read()'s never-throw contract.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MdLink extends MdContainerNode
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
