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
 * A "[label]: url \"title\"" link reference definition.
 *
 * Definitions do not render as document content; MarkdownReader collects
 * them on MdDocument::$linkReferenceDefinitions and resolves reference-style
 * links and images against them during inline parsing.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MdLinkReferenceDefinition
{
    public function __construct(
        public ByteSpan $span,
        public string $label,
        public string $normalizedLabel,
        public string $url,
        public ?string $title,
    ) {}
}
