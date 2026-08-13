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
 * An immutable, additive set of interpreted-text role capabilities.
 * Membership lookup is case-insensitive on ASCII, matching the rest of
 * the engine (see `Alto\Rst\Lint\Rule\DuplicateTargetRule::normalize`),
 * and resolves both a role's canonical name and any of its aliases.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class RoleSet
{
    /**
     * @param array<string, RoleSpec> $index keyed by normalized name or alias
     */
    private function __construct(
        private array $index = [],
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @param list<RoleSpec> $roles
     */
    public static function of(array $roles): self
    {
        $set = new self();

        foreach ($roles as $role) {
            $set = $set->with($role);
        }

        return $set;
    }

    /**
     * Adds or replaces a role, indexed by its name and every alias. An
     * existing entry reachable under the same normalized name or alias is
     * overridden in place.
     */
    public function with(RoleSpec $role): self
    {
        $index = $this->index;
        $index[self::normalize($role->name)] = $role;

        foreach ($role->aliases as $alias) {
            $index[self::normalize($alias)] = $role;
        }

        return new self($index);
    }

    /**
     * Additive merge: entries from $other override entries of this set
     * reachable under the same normalized name or alias. This is how
     * `sphinx()` extends `docutils()` and `symfony()` extends `sphinx()`.
     *
     * The Symfony `class` role is the reason this override is explicit:
     * `sphinx()` registers `class` as an alias of `py:class`, and
     * `symfony()` registers its own `class` role. After the merge,
     * `class` resolves to the Symfony role; `py:class` still resolves to
     * the Python domain role under its own canonical name.
     */
    public function merge(self $other): self
    {
        return new self([...$this->index, ...$other->index]);
    }

    /**
     * True when $nameOrAlias reaches a registered role, by canonical name
     * or by alias.
     */
    public function has(string $nameOrAlias): bool
    {
        return isset($this->index[self::normalize($nameOrAlias)]);
    }

    public function get(string $nameOrAlias): ?RoleSpec
    {
        return $this->index[self::normalize($nameOrAlias)] ?? null;
    }

    /**
     * @return list<string> canonical role names, deduplicated
     */
    public function names(): array
    {
        $names = [];

        foreach ($this->index as $role) {
            $names[$role->name] = true;
        }

        return array_keys($names);
    }

    private static function normalize(string $name): string
    {
        return strtolower($name);
    }
}
