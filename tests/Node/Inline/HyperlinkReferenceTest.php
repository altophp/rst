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
use Alto\Rst\Node\Inline\HyperlinkReference;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperlinkReference::class)]
final class HyperlinkReferenceTest extends TestCase
{
    public function testPhraseForm(): void
    {
        $node = new HyperlinkReference(ByteSpan::of(0, 15), 'example site');

        self::assertSame('example site', $node->text);
        self::assertNull($node->embeddedUri);
        self::assertFalse($node->anonymous);
        self::assertFalse($node->simple);
    }

    public function testEmbeddedUriForm(): void
    {
        $node = new HyperlinkReference(ByteSpan::of(0, 30), 'page', 'https://example.com/');

        self::assertSame('https://example.com/', $node->embeddedUri);
    }

    public function testAnonymousSimpleForm(): void
    {
        $node = new HyperlinkReference(ByteSpan::of(0, 8), 'name', null, true, true);

        self::assertTrue($node->anonymous);
        self::assertTrue($node->simple);
    }

    public function testEmptyTextIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HyperlinkReference(ByteSpan::of(0, 2), '');
    }

    public function testSimpleFormWithAnEmbeddedUriIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HyperlinkReference(ByteSpan::of(0, 8), 'name', 'https://example.com/', false, true);
    }
}
