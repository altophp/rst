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
use Alto\Rst\Node\Inline\InterpretedText;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InterpretedText::class)]
final class InterpretedTextTest extends TestCase
{
    public function testRolePrefixForm(): void
    {
        $node = new InterpretedText(ByteSpan::of(0, 12), 'ref', 'Target', true);

        self::assertSame('ref', $node->role);
        self::assertSame('Target', $node->text);
        self::assertTrue($node->rolePrefix);
    }

    public function testRoleSuffixForm(): void
    {
        $node = new InterpretedText(ByteSpan::of(0, 12), 'ref', 'Target');

        self::assertFalse($node->rolePrefix);
    }

    public function testDefaultRoleCarriesNoRole(): void
    {
        $node = new InterpretedText(ByteSpan::of(0, 6), null, 'Target');

        self::assertNull($node->role);
    }

    public function testEmptyRoleIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InterpretedText(ByteSpan::of(0, 6), '', 'Target');
    }

    public function testEmptyTextIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InterpretedText(ByteSpan::of(0, 6), 'ref', '');
    }

    public function testRolePrefixWithoutARoleIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InterpretedText(ByteSpan::of(0, 6), null, 'Target', true);
    }
}
