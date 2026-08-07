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

namespace Alto\Rst\Lint;

use Alto\Rst\Node\Document;
use Alto\Rst\Node\Node;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Source\Source;

/**
 * Shared immutable inputs for lint rules that need semantic analysis.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class LintContext
{
    public function __construct(
        public Document $document,
        public Source $source,
        public ReferenceGraph $references,
    ) {
    }

    /**
     * Walks typed inline nodes from the graph's existing inline cache.
     *
     * @return \Generator<int, Node>
     */
    public function inlineNodes(): \Generator
    {
        yield from new InlineNodeTraversal()->walk($this->document, $this->references);
    }
}
