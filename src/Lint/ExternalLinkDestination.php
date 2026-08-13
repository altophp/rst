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

use Alto\Rst\Reference\DefinitionKind;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Source\ByteSpan;

/**
 * One external hyperlink destination exposed to lint rules.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ExternalLinkDestination
{
    public function __construct(
        public string $url,
        public ByteSpan $span,
    ) {}

    /**
     * Includes explicit definitions and inline embedded destinations once.
     *
     * @return \Generator<int, self>
     */
    public static function fromGraph(ReferenceGraph $references): \Generator
    {
        $seen = [];

        foreach ($references->definitions(DefinitionKind::Hyperlink) as $definition) {
            if (null === $definition->destination) {
                continue;
            }

            $seen[spl_object_id($definition)] = true;

            yield new self(
                $definition->destination,
                $definition->destinationSpan ?? $definition->span,
            );
        }

        foreach ($references->references() as $reference) {
            $definition = $reference->target;

            if (
                null === $definition
                || DefinitionKind::Hyperlink !== $definition->kind
                || null === $definition->destination
                || isset($seen[spl_object_id($definition)])
            ) {
                continue;
            }

            $seen[spl_object_id($definition)] = true;

            yield new self(
                $definition->destination,
                $definition->destinationSpan ?? $reference->span,
            );
        }
    }
}
