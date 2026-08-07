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

use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Text;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Paragraph::class)]
final class ParagraphTest extends TestCase
{
    public function testConstruction(): void
    {
        $text = new Text(ByteSpan::of(7, 5), 'Hello');
        $paragraph = new Paragraph(ByteSpan::of(7, 5), $text);

        self::assertSame(7, $paragraph->span()->start);
        self::assertSame(5, $paragraph->span()->length);
        self::assertSame($text, $paragraph->text);
        self::assertSame([$text], $paragraph->children());
    }
}
