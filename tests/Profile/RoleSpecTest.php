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

use Alto\Rst\Profile\RoleSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoleSpec::class)]
final class RoleSpecTest extends TestCase
{
    public function testConstructorStoresShape(): void
    {
        $role = new RoleSpec('pep-reference', ['pep']);

        self::assertSame('pep-reference', $role->name);
        self::assertSame(['pep'], $role->aliases);
    }

    public function testDefaultsToNoAliases(): void
    {
        $role = new RoleSpec('emphasis');

        self::assertSame([], $role->aliases);
    }
}
