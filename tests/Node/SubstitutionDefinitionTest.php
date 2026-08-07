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
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\SubstitutionDefinition;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubstitutionDefinition::class)]
final class SubstitutionDefinitionTest extends TestCase
{
    public function testConstruction(): void
    {
        $directive = new Directive(ByteSpan::of(10, 16), 'replace', ['Alto Rst']);
        $definition = new SubstitutionDefinition(ByteSpan::of(0, 26), 'project name', $directive);

        self::assertSame(0, $definition->span()->start);
        self::assertSame('project name', $definition->name);
        self::assertSame($directive, $definition->directive);
        self::assertSame([$directive], $definition->children());
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SubstitutionDefinition(
            ByteSpan::of(0, 10),
            '',
            new Directive(ByteSpan::of(0, 10), 'replace'),
        );
    }
}
