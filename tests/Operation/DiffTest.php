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

use Alto\Rst\Operation\Diff;
use Alto\Rst\Operation\DiffHunk;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Diff::class)]
#[CoversClass(DiffHunk::class)]
final class DiffTest extends TestCase
{
    public function testEqualBytesProduceAnEmptyDiff(): void
    {
        $diff = Diff::between("same\r\n", "same\r\n");

        self::assertTrue($diff->isEmpty());
        self::assertSame('', $diff->toUnifiedString());
        self::assertSame([], $diff->hunks());
    }

    public function testProducesAUnifiedDiffWithSeparateContextHunks(): void
    {
        $original = implode("\n", range(1, 12))."\n";
        $edited = "one\n2\n3\n4\n5\n6\n7\n8\n9\n10\n11\ntwelve\n";
        $diff = Diff::between($original, $edited, 'a/example.rst', 'b/example.rst', 1);

        self::assertFalse($diff->isEmpty());
        self::assertCount(2, $diff->hunks());
        self::assertSame(
            <<<'DIFF'
                --- a/example.rst
                +++ b/example.rst
                @@ -1,2 +1,2 @@
                -1
                +one
                 2
                @@ -11,2 +11,2 @@
                 11
                -12
                +twelve

                DIFF,
            $diff->toUnifiedString(),
        );
    }

    public function testLargeInputsUseTheBoundedFallback(): void
    {
        $original = implode("\n", range(1, 1100))."\n";
        $edited = str_replace("\n550\n", "\nchanged\n", $original);
        $diff = Diff::between($original, $edited);

        self::assertFalse($diff->isEmpty());
        self::assertStringContainsString("-550\n+changed\n", $diff->toUnifiedString());
    }

    public function testHandlesEmptyInputsInsertionsDeletionsAndCrLf(): void
    {
        $addition = Diff::between('', "first\r\nsecond\r\n");
        self::assertSame(
            "--- original\n+++ edited\n@@ -0,0 +1,2 @@\n+first\n+second\n",
            $addition->toUnifiedString(),
        );

        $deletion = Diff::between("first\nsecond\n", '');
        self::assertSame(
            "--- original\n+++ edited\n@@ -1,2 +0,0 @@\n-first\n-second\n",
            $deletion->toUnifiedString(),
        );

        $insertion = Diff::between("first\nthird\n", "first\nsecond\nthird\n");
        self::assertStringContainsString("+second\n", $insertion->toUnifiedString());
    }
}
