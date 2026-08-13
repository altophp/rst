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

use Alto\Rst\Node\Node;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Parser\ParseResult;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\TestCase;

abstract class ParserTestCase extends TestCase
{
    protected static function parseRst(string $rst): ParseResult
    {
        return new BlockParser()->parse(Source::fromString($rst));
    }

    /**
     * @return list<string>
     */
    protected static function problemCodes(ParseResult $result): array
    {
        return array_map(
            static fn(Problem $problem): string => $problem->code,
            $result->problems()->problems(),
        );
    }

    protected static function assertSpan(int $start, int $end, Node $node): void
    {
        self::assertSame(
            ['start' => $start, 'end' => $end],
            ['start' => $node->span()->start, 'end' => $node->span()->end()],
        );
    }

    protected static function assertNoProblems(ParseResult $result): void
    {
        self::assertSame([], self::problemCodes($result));
    }
}
