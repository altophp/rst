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

namespace Alto\Rst\Tests\Convert;

use Alto\Rst\Convert\ConversionIssue;
use Alto\Rst\Convert\IssueKind;
use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConversionIssue::class)]
final class ConversionIssueTest extends TestCase
{
    public function testKeepsConstructMessageKindAndSpan(): void
    {
        $span = ByteSpan::of(4, 12);
        $issue = new ConversionIssue('directive:toctree', 'No Markdown equivalent.', IssueKind::Unsupported, $span);

        self::assertSame('directive:toctree', $issue->construct);
        self::assertSame('No Markdown equivalent.', $issue->message);
        self::assertSame(IssueKind::Unsupported, $issue->kind);
        self::assertSame($span, $issue->span);
    }

    public function testDefaultsToUnsupportedWithoutSpan(): void
    {
        $issue = new ConversionIssue('role:ref', 'Unresolved reference.');

        self::assertSame(IssueKind::Unsupported, $issue->kind);
        self::assertNull($issue->span);
    }

    public function testNamedConstructorsSetTheirKind(): void
    {
        self::assertSame(IssueKind::Unsupported, ConversionIssue::unsupported('a', 'm')->kind);
        self::assertSame(IssueKind::Lossy, ConversionIssue::lossy('b', 'm')->kind);
        self::assertSame(IssueKind::Approximated, ConversionIssue::approximated('c', 'm')->kind);
    }

    public function testNamedConstructorsCarryTheSpan(): void
    {
        $span = ByteSpan::of(0, 3);

        self::assertSame($span, ConversionIssue::unsupported('a', 'm', $span)->span);
        self::assertSame($span, ConversionIssue::lossy('b', 'm', $span)->span);
        self::assertSame($span, ConversionIssue::approximated('c', 'm', $span)->span);
    }

    public function testRejectsAnEmptyConstruct(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('construct must not be empty');

        new ConversionIssue('', 'message');
    }

    public function testRejectsAnEmptyMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('message must not be empty');

        new ConversionIssue('construct', '');
    }
}
