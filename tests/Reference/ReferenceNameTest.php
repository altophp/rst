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

namespace Alto\Rst\Tests\Reference;

use Alto\Rst\Reference\ReferenceName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReferenceName::class)]
final class ReferenceNameTest extends TestCase
{
    public function testNormalizationFoldsAsciiCaseAndWhitespace(): void
    {
        self::assertSame('a mixed name', ReferenceName::normalize(" \tA \n Mixed   Name "));
    }

    public function testNormalizationKeepsTheAsciiOnlyPolicy(): void
    {
        self::assertSame('ÉtÉ', ReferenceName::normalize('ÉTÉ'));
    }

    public function testIdProducesASafeLowercaseFragmentSeed(): void
    {
        self::assertSame('a-name:part', ReferenceName::id('A name:part'));
    }
}
