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
use Alto\Rst\Node\Inline\StandaloneHyperlink;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StandaloneHyperlink::class)]
final class StandaloneHyperlinkTest extends TestCase
{
    public function testConstruction(): void
    {
        $node = new StandaloneHyperlink(ByteSpan::of(6, 20), 'https://example.com/');

        self::assertSame(26, $node->span()->end());
        self::assertSame('https://example.com/', $node->uri);
    }

    public function testEmptyUriIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StandaloneHyperlink(ByteSpan::of(0, 0), '');
    }
}
