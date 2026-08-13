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

namespace Alto\Rst\Operation;

use Alto\Rst\Exception\PatchConflictException;
use Alto\Rst\Exception\SourcePatchException;

/**
 * Applies disjoint patches against immutable original source bytes.
 *
 * Every patch is validated before output construction begins. Spans always
 * address the original source, never the output of a preceding patch.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class SourcePatchApplier
{
    /**
     * @param list<SourcePatch> $patches
     */
    public function apply(string $source, array $patches): PatchResult
    {
        if ([] === $patches) {
            return new PatchResult($source, []);
        }

        $patches = $this->sorted($patches);
        $this->validate($patches, \strlen($source));

        $bytes = '';
        $cursor = 0;

        foreach ($patches as $patch) {
            $bytes .= substr($source, $cursor, $patch->span->start - $cursor);
            $bytes .= $patch->replacement;
            $cursor = $patch->span->start + $patch->span->length;
        }

        return new PatchResult($bytes . substr($source, $cursor), $patches);
    }

    /**
     * @param list<SourcePatch> $patches
     *
     * @return list<SourcePatch>
     */
    private function sorted(array $patches): array
    {
        usort($patches, static function (SourcePatch $left, SourcePatch $right): int {
            $byStart = $left->span->start <=> $right->span->start;

            if (0 !== $byStart) {
                return $byStart;
            }

            return $left->span->length <=> $right->span->length;
        });

        return $patches;
    }

    /**
     * @param list<SourcePatch> $patches
     */
    private function validate(array $patches, int $sourceLength): void
    {
        $previous = null;

        foreach ($patches as $patch) {
            $start = $patch->span->start;
            $length = $patch->span->length;

            if ($start > PHP_INT_MAX - $length) {
                throw new SourcePatchException(\sprintf('Source patch span at byte %d with length %d overflows the byte offset range.', $start, $length));
            }

            $end = $start + $length;

            if ($end > $sourceLength) {
                throw new SourcePatchException(\sprintf('Source patch span [%d, %d) exceeds the %d source bytes.', $start, $end, $sourceLength));
            }

            if ($previous instanceof SourcePatch) {
                $this->assertCompatible($previous, $patch);
            }

            $previous = $patch;
        }
    }

    private function assertCompatible(SourcePatch $left, SourcePatch $right): void
    {
        $leftStart = $left->span->start;
        $leftEnd = $leftStart + $left->span->length;
        $rightStart = $right->span->start;
        $rightEnd = $rightStart + $right->span->length;

        if ($left->span->isEmpty() && $right->span->isEmpty() && $leftStart === $rightStart) {
            throw new PatchConflictException(\sprintf('Concurrent source insertions at byte %d are ambiguous.', $leftStart));
        }

        $overlap = $rightStart < $leftEnd
            || ($right->span->isEmpty() && $rightStart > $leftStart && $rightStart < $leftEnd)
            || ($left->span->isEmpty() && $leftStart > $rightStart && $leftStart < $rightEnd);

        if ($overlap) {
            throw new PatchConflictException(\sprintf('Overlapping source patches at [%d, %d) and [%d, %d).', $leftStart, $leftEnd, $rightStart, $rightEnd));
        }
    }
}
