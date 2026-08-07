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

namespace Alto\Rst\Tests\Parser;

use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Parser\ParseResult;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParseResult::class)]
final class ParseResultTest extends TestCase
{
    public function testMatchesOnlyTheExactSourceBytes(): void
    {
        $source = Source::fromString("Title\n=====\n");
        $result = new BlockParser()->parse($source);

        self::assertTrue($result->matchesSource($source));
        self::assertTrue($result->matchesSource(Source::fromString($source->bytes)));
        self::assertFalse($result->matchesSource(Source::fromString("Other\n=====\n")));
    }
}
