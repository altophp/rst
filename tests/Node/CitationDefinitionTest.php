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
use Alto\Rst\Node\CitationDefinition;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Text;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CitationDefinition::class)]
final class CitationDefinitionTest extends TestCase
{
    public function testConstruction(): void
    {
        $paragraph = new Paragraph(ByteSpan::of(12, 4), new Text(ByteSpan::of(12, 4), 'Body'));
        $definition = new CitationDefinition(ByteSpan::of(0, 16), 'CIT2002', [$paragraph]);

        self::assertSame(0, $definition->span()->start);
        self::assertSame('CIT2002', $definition->label);
        self::assertSame([$paragraph], $definition->children());
    }

    public function testEmptyBody(): void
    {
        self::assertSame([], new CitationDefinition(ByteSpan::of(0, 10), 'CIT')->children());
    }

    public function testEmptyLabelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CitationDefinition(ByteSpan::of(0, 6), '');
    }
}
