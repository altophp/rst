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

namespace Alto\Rst\Tests\Profile;

use Alto\Rst\Profile\RoleSet;
use Alto\Rst\Profile\RoleSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoleSet::class)]
final class RoleSetTest extends TestCase
{
    public function testEmptySetHasNothing(): void
    {
        $set = RoleSet::empty();

        self::assertFalse($set->has('emphasis'));
        self::assertNull($set->get('emphasis'));
        self::assertSame([], $set->names());
    }

    public function testOfBuildsASetFromAList(): void
    {
        $set = RoleSet::of([
            new RoleSpec('emphasis'),
            new RoleSpec('pep-reference', ['pep']),
        ]);

        self::assertTrue($set->has('emphasis'));
        self::assertTrue($set->has('pep-reference'));
        self::assertTrue($set->has('pep'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function caseVariants(): iterable
    {
        yield 'lowercase name' => ['pep-reference'];
        yield 'uppercase name' => ['PEP-REFERENCE'];
        yield 'lowercase alias' => ['pep'];
        yield 'uppercase alias' => ['PEP'];
        yield 'mixed case alias' => ['PeP'];
    }

    #[DataProvider('caseVariants')]
    public function testMembershipLookupIsCaseInsensitiveOnAsciiForNameAndAlias(string $lookup): void
    {
        $set = RoleSet::of([new RoleSpec('pep-reference', ['pep'])]);

        self::assertTrue($set->has($lookup));
        self::assertSame('pep-reference', $set->get($lookup)?->name);
    }

    public function testGetReturnsNullForUnknownRole(): void
    {
        $set = RoleSet::of([new RoleSpec('emphasis')]);

        self::assertNull($set->get('bogus'));
    }

    public function testWithIndexesEveryAlias(): void
    {
        $set = RoleSet::empty()->with(new RoleSpec('py:class', ['class']));

        self::assertTrue($set->has('py:class'));
        self::assertTrue($set->has('class'));
        self::assertSame('py:class', $set->get('class')?->name);
    }

    public function testMergeIsAdditive(): void
    {
        $base = RoleSet::of([new RoleSpec('emphasis')]);
        $extra = RoleSet::of([new RoleSpec('ref')]);

        $merged = $base->merge($extra);

        self::assertTrue($merged->has('emphasis'));
        self::assertTrue($merged->has('ref'));
    }

    public function testMergeOverridesAnAliasWithTheOtherSetsCanonicalRole(): void
    {
        // This is the Sphinx-to-Symfony `class` case from the contract:
        // sphinx() aliases `class` to `py:class`; symfony() registers its
        // own `class` role, which must win the lookup after merging.
        $sphinxRoles = RoleSet::of([new RoleSpec('py:class', ['class'])]);
        $symfonyRoles = RoleSet::of([new RoleSpec('class')]);

        $merged = $sphinxRoles->merge($symfonyRoles);

        self::assertSame('class', $merged->get('class')?->name);
        // The Python domain role is still reachable under its own name.
        self::assertSame('py:class', $merged->get('py:class')?->name);
    }

    public function testNamesAreDeduplicatedAndExcludeBareAliasEntries(): void
    {
        $set = RoleSet::of([
            new RoleSpec('pep-reference', ['pep']),
            new RoleSpec('rfc-reference', ['rfc']),
        ]);

        $names = $set->names();
        sort($names);

        self::assertSame(['pep-reference', 'rfc-reference'], $names);
    }
}
