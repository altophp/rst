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

/**
 * A line-oriented unified diff over two exact byte strings.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Diff
{
    private const int MAX_LCS_CELLS = 1_000_000;

    /**
     * @param list<DiffHunk> $hunks
     */
    private function __construct(
        public bool $isEmpty,
        public string $unified,
        private array $hunks,
    ) {}

    public static function between(
        string $originalBytes,
        string $editedBytes,
        string $from = 'original',
        string $to = 'edited',
        int $context = 3,
    ): self {
        if ($originalBytes === $editedBytes) {
            return new self(true, '', []);
        }

        $original = self::splitLines($originalBytes);
        $edited = self::splitLines($editedBytes);
        $actions = self::diffActions($original, $edited);
        $hunks = self::buildHunks($actions, max(0, $context));
        $output = ["--- {$from}", "+++ {$to}"];

        foreach ($hunks as $hunk) {
            $output[] = \sprintf(
                '@@ -%d,%d +%d,%d @@',
                $hunk->originalStartLine,
                $hunk->originalLineCount,
                $hunk->editedStartLine,
                $hunk->editedLineCount,
            );

            foreach ($hunk->lines as $line) {
                $output[] = $line;
            }
        }

        return new self(false, implode("\n", $output) . "\n", $hunks);
    }

    public function isEmpty(): bool
    {
        return $this->isEmpty;
    }

    public function toUnifiedString(): string
    {
        return $this->unified;
    }

    /**
     * @return list<DiffHunk>
     */
    public function hunks(): array
    {
        return $this->hunks;
    }

    /**
     * @return list<string>
     */
    private static function splitLines(string $bytes): array
    {
        if ('' === $bytes) {
            return [];
        }

        $lines = [];
        $offset = 0;
        $length = \strlen($bytes);

        while ($offset < $length) {
            $start = $offset;
            $offset += strcspn($bytes, "\r\n", $offset);

            if ($offset < $length) {
                if ("\r" === $bytes[$offset] && $offset + 1 < $length && "\n" === $bytes[$offset + 1]) {
                    $offset += 2;
                } else {
                    ++$offset;
                }
            }

            $lines[] = substr($bytes, $start, $offset - $start);
        }

        return $lines;
    }

    /**
     * @param list<string> $original
     * @param list<string> $edited
     *
     * @return list<array{type: string, line: string, originalLine: int, editedLine: int}>
     */
    private static function diffActions(array $original, array $edited): array
    {
        if (
            0 !== \count($edited)
            && \count($original) > intdiv(self::MAX_LCS_CELLS, \count($edited))
        ) {
            return self::boundedActions($original, $edited);
        }

        return self::exactActions($original, $edited);
    }

    /**
     * @param list<string> $original
     * @param list<string> $edited
     *
     * @return list<array{type: string, line: string, originalLine: int, editedLine: int}>
     */
    private static function exactActions(array $original, array $edited): array
    {
        $lengths = self::lcsLengths($original, $edited);
        $actions = [];
        $originalIndex = 0;
        $editedIndex = 0;

        while ($originalIndex < \count($original) && $editedIndex < \count($edited)) {
            if ($original[$originalIndex] === $edited[$editedIndex]) {
                $actions[] = [
                    'type' => ' ',
                    'line' => $original[$originalIndex],
                    'originalLine' => $originalIndex + 1,
                    'editedLine' => $editedIndex + 1,
                ];
                ++$originalIndex;
                ++$editedIndex;

                continue;
            }

            if (($lengths[$originalIndex + 1][$editedIndex] ?? 0) >= ($lengths[$originalIndex][$editedIndex + 1] ?? 0)) {
                $actions[] = [
                    'type' => '-',
                    'line' => $original[$originalIndex],
                    'originalLine' => $originalIndex + 1,
                    'editedLine' => $editedIndex + 1,
                ];
                ++$originalIndex;

                continue;
            }

            $actions[] = [
                'type' => '+',
                'line' => $edited[$editedIndex],
                'originalLine' => $originalIndex + 1,
                'editedLine' => $editedIndex + 1,
            ];
            ++$editedIndex;
        }

        while ($originalIndex < \count($original)) {
            $actions[] = [
                'type' => '-',
                'line' => $original[$originalIndex],
                'originalLine' => $originalIndex + 1,
                'editedLine' => $editedIndex + 1,
            ];
            ++$originalIndex;
        }

        while ($editedIndex < \count($edited)) {
            $actions[] = [
                'type' => '+',
                'line' => $edited[$editedIndex],
                'originalLine' => $originalIndex + 1,
                'editedLine' => $editedIndex + 1,
            ];
            ++$editedIndex;
        }

        return $actions;
    }

    /**
     * Uses common prefix and suffix anchors when the exact LCS matrix would
     * exceed the fixed memory budget.
     *
     * @param list<string> $original
     * @param list<string> $edited
     *
     * @return list<array{type: string, line: string, originalLine: int, editedLine: int}>
     */
    private static function boundedActions(array $original, array $edited): array
    {
        $prefix = 0;
        $originalCount = \count($original);
        $editedCount = \count($edited);

        while (
            $prefix < $originalCount
            && $prefix < $editedCount
            && $original[$prefix] === $edited[$prefix]
        ) {
            ++$prefix;
        }

        $originalEnd = $originalCount;
        $editedEnd = $editedCount;

        while (
            $originalEnd > $prefix
            && $editedEnd > $prefix
            && $original[$originalEnd - 1] === $edited[$editedEnd - 1]
        ) {
            --$originalEnd;
            --$editedEnd;
        }

        $actions = [];

        for ($index = 0; $index < $prefix; ++$index) {
            $actions[] = [
                'type' => ' ',
                'line' => $original[$index],
                'originalLine' => $index + 1,
                'editedLine' => $index + 1,
            ];
        }

        for ($index = $prefix; $index < $originalEnd; ++$index) {
            $actions[] = [
                'type' => '-',
                'line' => $original[$index],
                'originalLine' => $index + 1,
                'editedLine' => $prefix + 1,
            ];
        }

        for ($index = $prefix; $index < $editedEnd; ++$index) {
            $actions[] = [
                'type' => '+',
                'line' => $edited[$index],
                'originalLine' => $originalEnd + 1,
                'editedLine' => $index + 1,
            ];
        }

        for (
            $originalIndex = $originalEnd, $editedIndex = $editedEnd;
            $originalIndex < $originalCount;
            ++$originalIndex, ++$editedIndex
        ) {
            $actions[] = [
                'type' => ' ',
                'line' => $original[$originalIndex],
                'originalLine' => $originalIndex + 1,
                'editedLine' => $editedIndex + 1,
            ];
        }

        return $actions;
    }

    /**
     * @param list<array{type: string, line: string, originalLine: int, editedLine: int}> $actions
     *
     * @return list<DiffHunk>
     */
    private static function buildHunks(array $actions, int $context): array
    {
        $changed = [];

        foreach ($actions as $index => $action) {
            if (' ' !== $action['type']) {
                $changed[] = $index;
            }
        }

        $hunks = [];
        $windowStart = null;
        $windowEnd = null;

        foreach ($changed as $index) {
            $start = max(0, $index - $context);
            $end = min(\count($actions) - 1, $index + $context);

            if (null === $windowStart || null === $windowEnd) {
                $windowStart = $start;
                $windowEnd = $end;

                continue;
            }

            if ($start > $windowEnd + 1) {
                $hunks[] = self::hunkFromActions(
                    array_slice($actions, $windowStart, $windowEnd - $windowStart + 1),
                );
                $windowStart = $start;
                $windowEnd = $end;

                continue;
            }

            $windowEnd = max($windowEnd, $end);
        }

        if (null !== $windowStart && null !== $windowEnd) {
            $hunks[] = self::hunkFromActions(
                array_slice($actions, $windowStart, $windowEnd - $windowStart + 1),
            );
        }

        return $hunks;
    }

    /**
     * @param list<array{type: string, line: string, originalLine: int, editedLine: int}> $actions
     */
    private static function hunkFromActions(array $actions): DiffHunk
    {
        $originalStart = null;
        $editedStart = null;
        $originalCount = 0;
        $editedCount = 0;
        $lines = [];

        foreach ($actions as $action) {
            if ('+' !== $action['type']) {
                $originalStart ??= $action['originalLine'];
                ++$originalCount;
            }

            if ('-' !== $action['type']) {
                $editedStart ??= $action['editedLine'];
                ++$editedCount;
            }

            $lines[] = $action['type'] . rtrim($action['line'], "\r\n");
        }

        $first = $actions[0];
        $originalStart ??= max(0, $first['originalLine'] - 1);
        $editedStart ??= max(0, $first['editedLine'] - 1);

        return new DiffHunk($originalStart, $originalCount, $editedStart, $editedCount, $lines);
    }

    /**
     * @param list<string> $original
     * @param list<string> $edited
     *
     * @return array<int, array<int, int>>
     */
    private static function lcsLengths(array $original, array $edited): array
    {
        $originalCount = \count($original);
        $editedCount = \count($edited);
        $lengths = array_fill(0, $originalCount + 1, array_fill(0, $editedCount + 1, 0));

        for ($originalIndex = $originalCount - 1; $originalIndex >= 0; --$originalIndex) {
            for ($editedIndex = $editedCount - 1; $editedIndex >= 0; --$editedIndex) {
                if ($original[$originalIndex] === $edited[$editedIndex]) {
                    $lengths[$originalIndex][$editedIndex] = $lengths[$originalIndex + 1][$editedIndex + 1] + 1;

                    continue;
                }

                $lengths[$originalIndex][$editedIndex] = max(
                    $lengths[$originalIndex + 1][$editedIndex],
                    $lengths[$originalIndex][$editedIndex + 1],
                );
            }
        }

        return $lengths;
    }
}
