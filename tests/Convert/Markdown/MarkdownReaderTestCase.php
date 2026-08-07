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

namespace Alto\Rst\Tests\Convert\Markdown;

use Alto\Rst\Convert\Markdown\MarkdownReader;
use Alto\Rst\Convert\Markdown\MdDocument;
use Alto\Rst\Convert\Markdown\MdNode;
use PHPUnit\Framework\TestCase;

abstract class MarkdownReaderTestCase extends TestCase
{
    protected static function read(string $markdown): MdDocument
    {
        return new MarkdownReader()->read($markdown);
    }

    protected static function assertSpan(int $start, int $end, MdNode $node): void
    {
        self::assertSame(
            ['start' => $start, 'end' => $end],
            ['start' => $node->span()->start, 'end' => $node->span()->end()],
        );
    }
}
