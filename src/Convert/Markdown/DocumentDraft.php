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
 * The document under construction while MarkdownBlockParser scans it. See
 * BlockDraft for why finalization is a separate step from block scanning.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class DocumentDraft
{
    /**
     * @param list<BlockDraft>                $children
     * @param list<MdLinkReferenceDefinition> $definitions
     */
    public function __construct(
        private readonly ByteSpan $span,
        private readonly array $children,
        private readonly array $definitions,
    ) {}

    public function finalize(MarkdownInlineParser $inline): MdDocument
    {
        $children = [];

        foreach ($this->children as $child) {
            $children[] = $child->finalize($inline);
        }

        return new MdDocument($this->span, $children, $this->definitions);
    }

    /**
     * The definitions keyed by normalized label, first definition wins.
     *
     * @return array<string, MdLinkReferenceDefinition>
     */
    public function definitionsByLabel(): array
    {
        $byLabel = [];

        foreach ($this->definitions as $definition) {
            $byLabel[$definition->normalizedLabel] ??= $definition;
        }

        return $byLabel;
    }
}
