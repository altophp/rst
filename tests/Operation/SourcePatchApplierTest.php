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

namespace Alto\Rst\Tests\Operation;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Exception\PatchConflictException;
use Alto\Rst\Exception\RstExceptionInterface;
use Alto\Rst\Exception\SourcePatchException;
use Alto\Rst\Operation\PatchResult;
use Alto\Rst\Operation\SourcePatch;
use Alto\Rst\Operation\SourcePatchApplier;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourcePatchApplier::class)]
#[CoversClass(SourcePatch::class)]
#[CoversClass(PatchResult::class)]
final class SourcePatchApplierTest extends TestCase
{
    public function testSortsPatchesByOriginalByteOffsetBeforeApplyingThem(): void
    {
        $late = new SourcePatch(ByteSpan::between(4, 5), 'C');
        $early = new SourcePatch(ByteSpan::between(2, 3), 'B');

        $result = new SourcePatchApplier()->apply("a\nb\nc\n", [$late, $early]);

        self::assertSame("a\nB\nC\n", $result->bytes);
        self::assertSame([$early, $late], $result->patches);
    }

    public function testAppliesInsertionReplacementAndDeletionInOnePass(): void
    {
        $result = new SourcePatchApplier()->apply('abcdef', [
            new SourcePatch(ByteSpan::of(6, 0), '!'),
            new SourcePatch(ByteSpan::between(2, 4), 'CD'),
            new SourcePatch(ByteSpan::between(0, 1), ''),
            new SourcePatch(ByteSpan::of(1, 0), 'A'),
        ]);

        self::assertSame('AbCDef!', $result->bytes);
    }

    public function testOffsetsAddressUtf8BytesRatherThanCharacters(): void
    {
        $source = 'éclair';

        $result = new SourcePatchApplier()->apply($source, [
            new SourcePatch(ByteSpan::of(2, 5), 'toile'),
        ]);

        self::assertSame('étoile', $result->bytes);
    }

    /**
     * @param list<SourcePatch> $patches
     */
    #[DataProvider('sourceFormatProvider')]
    public function testPreservesAllBytesOutsidePatches(string $source, array $patches, string $expected): void
    {
        self::assertSame($expected, new SourcePatchApplier()->apply($source, $patches)->bytes);
    }

    /**
     * @return iterable<string, array{string, list<SourcePatch>, string}>
     */
    public static function sourceFormatProvider(): iterable
    {
        yield 'LF' => [
            "one\ntwo\n",
            [new SourcePatch(ByteSpan::between(4, 7), 'TWO')],
            "one\nTWO\n",
        ];

        yield 'CRLF' => [
            "one\r\ntwo\r\n",
            [new SourcePatch(ByteSpan::between(5, 8), 'TWO')],
            "one\r\nTWO\r\n",
        ];

        yield 'bare CR' => [
            "one\rtwo\r",
            [new SourcePatch(ByteSpan::between(4, 7), 'TWO')],
            "one\rTWO\r",
        ];

        yield 'UTF-8 BOM' => [
            "\xEF\xBB\xBFone\n",
            [new SourcePatch(ByteSpan::between(3, 6), 'ONE')],
            "\xEF\xBB\xBFONE\n",
        ];
    }

    public function testRejectsAnOffsetThatOverflowsTheIntegerRange(): void
    {
        $error = $this->captureException(static fn (): PatchResult => new SourcePatchApplier()->apply('', [
            new SourcePatch(ByteSpan::of(PHP_INT_MAX, 1), ''),
        ]));

        self::assertInstanceOf(SourcePatchException::class, $error);
        self::assertInstanceOf(RstExceptionInterface::class, $error);
        self::assertSame(
            \sprintf('Source patch span at byte %d with length 1 overflows the byte offset range.', PHP_INT_MAX),
            $error->getMessage(),
        );
    }

    public function testByteSpanRejectsAnInvalidRangeBeforeItBecomesAPatch(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ByteSpan::between(3, 2);
    }

    #[DataProvider('outOfBoundsProvider')]
    public function testRejectsOutOfBoundsSpans(ByteSpan $span, string $expectedMessage): void
    {
        $this->expectException(SourcePatchException::class);
        $this->expectExceptionMessage($expectedMessage);

        new SourcePatchApplier()->apply('abc', [new SourcePatch($span, '')]);
    }

    /**
     * @return iterable<string, array{ByteSpan, string}>
     */
    public static function outOfBoundsProvider(): iterable
    {
        yield 'range end' => [
            ByteSpan::between(2, 4),
            'Source patch span [2, 4) exceeds the 3 source bytes.',
        ];

        yield 'insertion point' => [
            ByteSpan::of(4, 0),
            'Source patch span [4, 4) exceeds the 3 source bytes.',
        ];
    }

    #[DataProvider('overlapProvider')]
    public function testRejectsOverlappingPatches(SourcePatch $left, SourcePatch $right): void
    {
        $error = $this->captureException(static fn (): PatchResult => new SourcePatchApplier()->apply(
            'abcdef',
            [$right, $left],
        ));

        self::assertInstanceOf(PatchConflictException::class, $error);
        self::assertInstanceOf(RstExceptionInterface::class, $error);
        self::assertStringStartsWith('Overlapping source patches', $error->getMessage());
    }

    /**
     * @return iterable<string, array{SourcePatch, SourcePatch}>
     */
    public static function overlapProvider(): iterable
    {
        yield 'partial overlap' => [
            new SourcePatch(ByteSpan::between(1, 4), 'X'),
            new SourcePatch(ByteSpan::between(3, 5), 'Y'),
        ];

        yield 'contained range' => [
            new SourcePatch(ByteSpan::between(1, 5), 'X'),
            new SourcePatch(ByteSpan::between(2, 3), 'Y'),
        ];

        yield 'insertion inside range' => [
            new SourcePatch(ByteSpan::between(1, 5), 'X'),
            new SourcePatch(ByteSpan::of(3, 0), 'Y'),
        ];
    }

    public function testRejectsConcurrentInsertionsAtTheSameOffset(): void
    {
        $this->expectException(PatchConflictException::class);
        $this->expectExceptionMessage('Concurrent source insertions at byte 2 are ambiguous.');

        new SourcePatchApplier()->apply('abcd', [
            new SourcePatch(ByteSpan::of(2, 0), 'X'),
            new SourcePatch(ByteSpan::of(2, 0), 'Y'),
        ]);
    }

    public function testAllowsAnInsertionAtAReplacementBoundary(): void
    {
        $result = new SourcePatchApplier()->apply('abcd', [
            new SourcePatch(ByteSpan::between(1, 3), 'BC'),
            new SourcePatch(ByteSpan::of(1, 0), '['),
            new SourcePatch(ByteSpan::of(3, 0), ']'),
        ]);

        self::assertSame('a[BC]d', $result->bytes);
    }

    public function testValidatesEveryPatchBeforeProducingAResult(): void
    {
        $source = 'original';

        try {
            new SourcePatchApplier()->apply($source, [
                new SourcePatch(ByteSpan::between(0, 1), 'O'),
                new SourcePatch(ByteSpan::of(100, 0), '!'),
            ]);
            self::fail('Expected the invalid second patch to reject the complete operation.');
        } catch (SourcePatchException) {
            self::assertSame('original', $source);
        }
    }

    public function testNoPatchesReturnsTheOriginalBytesAndAnEmptyPlan(): void
    {
        $result = new SourcePatchApplier()->apply("original\r\n", []);

        self::assertSame("original\r\n", $result->bytes);
        self::assertSame([], $result->patches);
    }

    public function testNoOpPatchIsByteIdempotent(): void
    {
        $patch = new SourcePatch(ByteSpan::between(1, 3), 'bc');
        $applier = new SourcePatchApplier();

        $first = $applier->apply('abcd', [$patch]);
        $second = $applier->apply($first->bytes, [$patch]);

        self::assertSame('abcd', $first->bytes);
        self::assertSame($first->bytes, $second->bytes);
    }

    /**
     * @param \Closure(): PatchResult $operation
     */
    private function captureException(\Closure $operation): \Throwable
    {
        try {
            $operation();
            self::fail('Expected the patch operation to fail.');
        } catch (\Throwable $error) {
            return $error;
        }
    }
}
