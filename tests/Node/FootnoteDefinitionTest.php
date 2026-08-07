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
use Alto\Rst\Node\FootnoteDefinition;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Text;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FootnoteDefinition::class)]
final class FootnoteDefinitionTest extends TestCase
{
    public function testConstruction(): void
    {
        $paragraph = new Paragraph(ByteSpan::of(7, 4), new Text(ByteSpan::of(7, 4), 'Body'));
        $definition = new FootnoteDefinition(ByteSpan::of(0, 11), '#note', [$paragraph]);

        self::assertSame(0, $definition->span()->start);
        self::assertSame('#note', $definition->label);
        self::assertSame([$paragraph], $definition->children());
    }

    public function testEmptyBody(): void
    {
        self::assertSame([], new FootnoteDefinition(ByteSpan::of(0, 6), '*')->children());
    }

    public function testEmptyLabelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FootnoteDefinition(ByteSpan::of(0, 6), '');
    }
}
