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

use Alto\Rst\Node\Text;
use Alto\Rst\Node\Title;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Title::class)]
final class TitleTest extends TestCase
{
    public function testConstruction(): void
    {
        $text = new Text(ByteSpan::of(0, 5), 'Intro');
        $title = new Title(ByteSpan::of(0, 5), $text);

        self::assertSame(0, $title->span()->start);
        self::assertSame(5, $title->span()->length);
        self::assertSame($text, $title->text);
        self::assertSame([$text], $title->children());
    }
}
