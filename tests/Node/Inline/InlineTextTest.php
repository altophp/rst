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

use Alto\Rst\Node\Inline\InlineText;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InlineText::class)]
final class InlineTextTest extends TestCase
{
    public function testConstruction(): void
    {
        $text = new InlineText(ByteSpan::of(4, 12), 'a normalized');

        self::assertSame(4, $text->span()->start);
        self::assertSame(16, $text->span()->end());
        self::assertSame('a normalized', $text->text);
    }

    public function testWhitespaceOnlyTextIsAccepted(): void
    {
        $text = new InlineText(ByteSpan::of(0, 3), ' ');

        self::assertSame(' ', $text->text);
    }
}
