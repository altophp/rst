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

namespace Alto\Rst\Reference;

use Alto\Rst\Node\Node;
use Alto\Rst\Source\ByteSpan;

/**
 * One effective definition in the document-wide graph.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ReferenceDefinition
{
    public function __construct(
        public DefinitionKind $kind,
        public string $name,
        public string $normalizedName,
        public ByteSpan $span,
        public Node $node,
        public ?string $destination = null,
        public ?ByteSpan $destinationSpan = null,
        public ?SubstitutionKind $substitutionKind = null,
        public ?string $substitutionAlt = null,
    ) {
    }
}
