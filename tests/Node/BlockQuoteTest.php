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

use Alto\Rst\Node\BlockQuote;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Text;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlockQuote::class)]
final class BlockQuoteTest extends TestCase
{
    public function testConstruction(): void
    {
        $paragraph = new Paragraph(ByteSpan::of(4, 6), new Text(ByteSpan::of(4, 6), 'Quoted'));
        $quote = new BlockQuote(ByteSpan::of(4, 6), [$paragraph]);

        self::assertSame(4, $quote->span()->start);
        self::assertSame([$paragraph], $quote->children());
    }

    public function testEmptyBlockQuote(): void
    {
        $quote = new BlockQuote(ByteSpan::of(4, 0));

        self::assertSame([], $quote->children());
    }
}
