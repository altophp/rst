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

namespace Alto\Rst\Convert\Writer;

/**
 * Line-level string helpers shared by both conversion writers.
 *
 * Every helper preserves the writers' output rules: lines never carry
 * trailing whitespace, and blank lines stay completely empty.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Lines
{
    private function __construct()
    {
    }

    /**
     * Splits into lines, normalizing CRLF and CR terminators to LF first.
     *
     * @return list<string>
     */
    public static function split(string $text): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);

        return explode("\n", $normalized);
    }

    /**
     * Removes the widest common leading whitespace of the non-blank lines.
     */
    public static function dedent(string $text): string
    {
        $lines = self::split($text);
        $margin = null;

        foreach ($lines as $line) {
            if ('' === trim($line)) {
                continue;
            }

            $indent = \strlen($line) - \strlen(ltrim($line, " \t"));
            $margin = null === $margin ? $indent : min($margin, $indent);
        }

        if (null === $margin || 0 === $margin) {
            return implode("\n", array_map(rtrim(...), $lines));
        }

        $dedented = [];

        foreach ($lines as $line) {
            $dedented[] = rtrim(substr($line, min($margin, \strlen($line) - \strlen(ltrim($line, " \t")))));
        }

        return implode("\n", $dedented);
    }

    /**
     * Prefixes the first line with $first and every following line with
     * $rest; blank lines get rtrim($rest) so they never carry trailing
     * whitespace.
     */
    public static function prefix(string $block, string $first, string $rest): string
    {
        $lines = self::split($block);
        $prefixed = [];

        foreach ($lines as $index => $line) {
            $head = 0 === $index ? $first : $rest;
            $prefixed[] = '' === $line ? rtrim($head) : $head.$line;
        }

        return implode("\n", $prefixed);
    }

    /**
     * Indents every non-blank line.
     */
    public static function indent(string $block, string $indent): string
    {
        return self::prefix($block, $indent, $indent);
    }

    /**
     * Prefixes every line with a Markdown block quote marker: "> " before
     * content, a bare ">" on blank lines.
     */
    public static function quote(string $block): string
    {
        return self::prefix($block, '> ', '> ');
    }
}
