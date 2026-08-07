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

namespace Alto\Rst\Tests\Node;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Node\HyperlinkTarget;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperlinkTarget::class)]
final class HyperlinkTargetTest extends TestCase
{
    public function testNamedTarget(): void
    {
        $target = new HyperlinkTarget(ByteSpan::of(0, 30), 'symfony', 'https://symfony.com');

        self::assertSame(0, $target->span()->start);
        self::assertSame('symfony', $target->name);
        self::assertSame('https://symfony.com', $target->target);
        self::assertFalse($target->anonymous);
    }

    public function testInternalTargetHasAnEmptyTarget(): void
    {
        $target = new HyperlinkTarget(ByteSpan::of(0, 9), 'anchor', '');

        self::assertSame('anchor', $target->name);
        self::assertSame('', $target->target);
    }

    public function testAnonymousTarget(): void
    {
        $target = new HyperlinkTarget(ByteSpan::of(0, 25), '', 'https://symfony.com', true);

        self::assertSame('', $target->name);
        self::assertTrue($target->anonymous);
    }

    public function testAnonymousTargetWithANameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HyperlinkTarget(ByteSpan::of(0, 25), 'symfony', 'https://symfony.com', true);
    }

    public function testNamedTargetWithoutANameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HyperlinkTarget(ByteSpan::of(0, 25), '', 'https://symfony.com');
    }
}
