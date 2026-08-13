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

namespace Alto\Rst\Profile;

/**
 * An interpreted-text role's capability description: its canonical name
 * plus the alternate spellings it is also known by (for example
 * `pep-reference` and its abbreviation `pep`).
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class RoleSpec
{
    /**
     * @param list<string> $aliases alternate names this role also answers to
     */
    public function __construct(
        public string $name,
        public array $aliases = [],
    ) {}
}
