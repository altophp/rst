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
use Alto\Rst\Node\Inline\InlineLiteral;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InlineLiteral::class)]
final class InlineLiteralTest extends TestCase
{
    public function testConstruction(): void
    {
        $literal = new InlineLiteral(ByteSpan::of(0, 11), 'a \\ verbatim');

        self::assertSame(11, $literal->span()->length);
        self::assertSame('a \\ verbatim', $literal->text);
    }

    public function testEmptyTextIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InlineLiteral(ByteSpan::of(0, 4), '');
    }
}
