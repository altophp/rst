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
use Alto\Rst\Node\Inline\CitationReference;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CitationReference::class)]
final class CitationReferenceTest extends TestCase
{
    public function testConstruction(): void
    {
        $node = new CitationReference(ByteSpan::of(0, 10), 'CIT2002');

        self::assertSame('CIT2002', $node->label);
    }

    public function testEmptyLabelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CitationReference(ByteSpan::of(0, 3), '');
    }
}
