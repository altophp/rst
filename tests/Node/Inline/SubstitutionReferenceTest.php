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

namespace Alto\Rst\Tests\Node\Inline;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Node\Inline\SubstitutionReference;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubstitutionReference::class)]
final class SubstitutionReferenceTest extends TestCase
{
    public function testConstruction(): void
    {
        $node = new SubstitutionReference(ByteSpan::of(0, 6), 'name');

        self::assertSame('name', $node->name);
        self::assertFalse($node->reference);
        self::assertFalse($node->anonymous);
    }

    public function testReferenceForm(): void
    {
        $node = new SubstitutionReference(ByteSpan::of(0, 7), 'name', true);

        self::assertTrue($node->reference);
        self::assertFalse($node->anonymous);
    }

    public function testAnonymousReferenceForm(): void
    {
        $node = new SubstitutionReference(ByteSpan::of(0, 8), 'name', true, true);

        self::assertTrue($node->reference);
        self::assertTrue($node->anonymous);
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SubstitutionReference(ByteSpan::of(0, 2), '');
    }
}
