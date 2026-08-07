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

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Format\FormatOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FormatOptions::class)]
final class FormatOptionsTest extends TestCase
{
    public function testDefaultsEnableTheConservativePasses(): void
    {
        $options = new FormatOptions();

        self::assertTrue($options->normalizeSectionAdornments);
        self::assertSame('-', $options->bulletMarker);
        self::assertTrue($options->alignSimpleTables);
        self::assertNull($options->lineWidth);
    }

    public function testKeepsTheExistingPositionalLineWidthArgument(): void
    {
        $options = new FormatOptions(true, '-', 80);

        self::assertSame(80, $options->lineWidth);
        self::assertTrue($options->alignSimpleTables);
    }

    #[DataProvider('invalidBulletMarkers')]
    public function testRejectsAnUnsafeBulletMarker(string $marker): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bullet marker must be');

        new FormatOptions(bulletMarker: $marker);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBulletMarkers(): iterable
    {
        yield 'empty' => [''];
        yield 'multiple bytes' => ['--'];
        yield 'unicode' => ["\u{2022}"];
        yield 'enumerator' => ['1.'];
    }

    public function testRejectsAnInvalidLineWidth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Line width must be >= 1 or null, got 0.');

        new FormatOptions(lineWidth: 0);
    }
}
