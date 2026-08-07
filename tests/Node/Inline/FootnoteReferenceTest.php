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
use Alto\Rst\Node\Inline\FootnoteReference;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FootnoteReference::class)]
final class FootnoteReferenceTest extends TestCase
{
    public function testConstruction(): void
    {
        $node = new FootnoteReference(ByteSpan::of(3, 4), '#note');

        self::assertSame(3, $node->span()->start);
        self::assertSame('#note', $node->label);
    }

    public function testEmptyLabelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FootnoteReference(ByteSpan::of(0, 3), '');
    }
}
