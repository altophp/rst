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

namespace Alto\Rst\Node;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Source\ByteSpan;

/**
 * A citation definition with its raw label and parsed block body.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CitationDefinition extends ContainerNode
{
    /**
     * @param list<Node> $body
     */
    public function __construct(
        ByteSpan $span,
        public string $label,
        public array $body = [],
    ) {
        if ('' === $label) {
            throw new InvalidArgumentException('Citation definition label must not be empty.');
        }

        parent::__construct($span);
    }

    /**
     * @return list<Node>
     */
    public function children(): array
    {
        return $this->body;
    }
}
