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

namespace Alto\Rst\Node\Inline;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Node\Node;
use Alto\Rst\Source\ByteSpan;

/**
 * Role-interpreted text.
 *
 * A null role means the source used the default role. `$rolePrefix` records
 * which side carried the role so a rewrite keeps the original style.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class InterpretedText extends Node
{
    public function __construct(
        ByteSpan $span,
        public ?string $role,
        public string $text,
        public bool $rolePrefix = false,
    ) {
        if ('' === $role) {
            throw new InvalidArgumentException('Interpreted text role must not be empty.');
        }

        if ('' === $text) {
            throw new InvalidArgumentException('Interpreted text must not be empty.');
        }

        if ($rolePrefix && null === $role) {
            throw new InvalidArgumentException('Interpreted text without a role has no role prefix.');
        }

        parent::__construct($span);
    }
}
