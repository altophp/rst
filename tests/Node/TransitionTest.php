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

use Alto\Rst\Node\Transition;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Transition::class)]
final class TransitionTest extends TestCase
{
    public function testConstruction(): void
    {
        $transition = new Transition(ByteSpan::of(20, 4));

        self::assertSame(20, $transition->span()->start);
        self::assertSame(4, $transition->span()->length);
    }
}
