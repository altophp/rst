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

namespace Alto\Rst\Tests\Fix;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Fix\FixOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FixOptions::class)]
final class FixOptionsTest extends TestCase
{
    public function testRejectsAnInvalidBlankLineLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum blank line count must be >= 1 or null, got 0.');

        new FixOptions(maxBlankLines: 0);
    }

    public function testCanDisableEachPass(): void
    {
        $options = new FixOptions(
            removeTrailingWhitespace: false,
            maxBlankLines: null,
            blankLineAfterAnchor: false,
            blankLineBeforeDirectiveBody: false,
            normalizeDefaultRoleAsLiteral: false,
        );

        self::assertFalse($options->removeTrailingWhitespace);
        self::assertNull($options->maxBlankLines);
        self::assertFalse($options->blankLineAfterAnchor);
        self::assertFalse($options->blankLineBeforeDirectiveBody);
        self::assertFalse($options->normalizeDefaultRoleAsLiteral);
    }
}
