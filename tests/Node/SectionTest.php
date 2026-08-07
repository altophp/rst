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

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Section;
use Alto\Rst\Node\Text;
use Alto\Rst\Node\Title;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Section::class)]
final class SectionTest extends TestCase
{
    public function testConstruction(): void
    {
        $title = new Title(ByteSpan::of(0, 5), new Text(ByteSpan::of(0, 5), 'Intro'));
        $paragraph = new Paragraph(ByteSpan::of(13, 5), new Text(ByteSpan::of(13, 5), 'First'));

        $section = new Section(ByteSpan::of(0, 18), 2, $title, [$paragraph], '-', true);

        self::assertSame(0, $section->span()->start);
        self::assertSame(18, $section->span()->length);
        self::assertSame(2, $section->level);
        self::assertSame($title, $section->title);
        self::assertSame('-', $section->adornment);
        self::assertTrue($section->hasOverline);
        self::assertSame([$paragraph], $section->body());
    }

    public function testChildrenPutTheTitleBeforeTheBody(): void
    {
        $title = new Title(ByteSpan::of(0, 5), new Text(ByteSpan::of(0, 5), 'Intro'));
        $paragraph = new Paragraph(ByteSpan::of(13, 5), new Text(ByteSpan::of(13, 5), 'First'));

        $section = new Section(ByteSpan::of(0, 18), 1, $title, [$paragraph], '=', false);

        self::assertSame([$title, $paragraph], $section->children());
    }

    public function testEmptyBody(): void
    {
        $title = new Title(ByteSpan::of(0, 5), new Text(ByteSpan::of(0, 5), 'Intro'));

        $section = new Section(ByteSpan::of(0, 12), 1, $title, [], '=', false);

        self::assertSame([], $section->body());
        self::assertSame([$title], $section->children());
    }

    public function testLevelBelowOneIsRejected(): void
    {
        $title = new Title(ByteSpan::of(0, 5), new Text(ByteSpan::of(0, 5), 'Intro'));

        $this->expectException(InvalidArgumentException::class);

        new Section(ByteSpan::of(0, 12), 0, $title, [], '=', false);
    }

    public function testMultiCharacterAdornmentIsRejected(): void
    {
        $title = new Title(ByteSpan::of(0, 5), new Text(ByteSpan::of(0, 5), 'Intro'));

        $this->expectException(InvalidArgumentException::class);

        new Section(ByteSpan::of(0, 12), 1, $title, [], '==', false);
    }

    public function testEmptyAdornmentIsRejected(): void
    {
        $title = new Title(ByteSpan::of(0, 5), new Text(ByteSpan::of(0, 5), 'Intro'));

        $this->expectException(InvalidArgumentException::class);

        new Section(ByteSpan::of(0, 12), 1, $title, [], '', false);
    }
}
