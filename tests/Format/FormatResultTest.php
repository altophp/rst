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

namespace Alto\Rst\Tests\Format;

use Alto\Rst\Format\FormatResult;
use Alto\Rst\Operation\SourcePatch;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FormatResult::class)]
final class FormatResultTest extends TestCase
{
    public function testReportsWhetherAnyPatchChangedTheSource(): void
    {
        self::assertFalse(new FormatResult('same', [])->changed());
        self::assertTrue(new FormatResult('new', [
            new SourcePatch(ByteSpan::between(0, 3), 'new'),
        ])->changed());
    }
}
