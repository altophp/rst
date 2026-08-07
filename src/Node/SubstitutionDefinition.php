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
 * A substitution definition and the generic directive that defines it.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SubstitutionDefinition extends ContainerNode
{
    public function __construct(
        ByteSpan $span,
        public string $name,
        public Directive $directive,
    ) {
        if ('' === $name) {
            throw new InvalidArgumentException('Substitution definition name must not be empty.');
        }

        parent::__construct($span);
    }

    /**
     * @return list<Directive>
     */
    public function children(): array
    {
        return [$this->directive];
    }
}
