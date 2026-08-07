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

namespace Alto\Rst\Format;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Node\ContainerNode;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Table;
use Alto\Rst\Node\TableCell;
use Alto\Rst\Node\TableRow;
use Alto\Rst\Node\TableStyle;
use Alto\Rst\Operation\SourcePatch;
use Alto\Rst\Operation\SourcePatchApplier;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Parser\ParseResult;
use Alto\Rst\Problem\ProblemReport;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Line;
use Alto\Rst\Source\Source;

/**
 * Aligns structurally stable simple tables without rewriting cell content.
 *
 * A stable table is rectangular, has no spans, and keeps every cell on one
 * physical line. The whole candidate is reparsed before any patch is exposed.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SimpleTableAligner
{
    /**
     * @return list<SourcePatch>
     */
    public function patches(
        ParseResult $result,
        Source $source,
        ?Profile $profile = null,
    ): array {
        if (!$result->matchesSource($source)) {
            throw new InvalidArgumentException('Parse result was not produced from the simple table aligner source.');
        }

        if ($result->problems()->hasProblems()) {
            return [];
        }

        $patches = [];

        foreach ($result->document()->descendants() as $node) {
            if (!$node instanceof Table || TableStyle::Simple !== $node->style) {
                continue;
            }

            $patch = $this->tablePatch($node, $source);

            if (null !== $patch) {
                $patches[] = $patch;
            }
        }

        if ([] === $patches) {
            return [];
        }

        $profile ??= Profile::docutils();
        $candidate = new SourcePatchApplier()->apply($source->bytes, $patches);
        $reparsed = new BlockParser()->parse(Source::fromString($candidate->bytes), $profile);

        if ($reparsed->problems()->hasProblems()) {
            return [];
        }

        if (
            $this->problemSignature($result->references()->problems())
            !== $this->problemSignature($reparsed->references()->problems())
        ) {
            return [];
        }

        if ($this->semanticShape($result->document()) !== $this->semanticShape($reparsed->document())) {
            return [];
        }

        return $patches;
    }

    private function tablePatch(Table $table, Source $source): ?SourcePatch
    {
        $columnCount = \count($table->columnWidths);
        $rows = [...$table->head, ...$table->body];

        if ($columnCount < 2 || [] === $rows) {
            return null;
        }

        $content = [];
        foreach ($rows as $row) {
            $cells = $row->children();

            if (\count($cells) !== $columnCount || !$this->rowOccupiesOneLine($row, $source)) {
                return null;
            }

            $contentRow = [];

            foreach ($cells as $index => $cell) {
                $text = $this->stableCellText($cell, $source);

                if (null === $text) {
                    return null;
                }

                $width = $this->displayWidth($text);

                if (null === $width) {
                    return null;
                }

                if ($width !== self::characterLength($text)) {
                    return null;
                }

                $contentRow[] = $text;
            }

            $content[] = $contentRow;
        }

        $widths = [];

        for ($index = 0; $index < $columnCount; ++$index) {
            $width = 1;

            foreach ($content as $row) {
                $width = max($width, $this->displayWidth($row[$index]) ?? 1);
            }

            $widths[] = $width;
        }

        $lines = $this->linesForSpan($source, $table->span());
        $expectedLineCount = \count($rows) + 2 + ([] === $table->head ? 0 : 1);

        if (\count($lines) !== $expectedLineCount) {
            return null;
        }

        $first = $lines[0];
        $last = $lines[\count($lines) - 1];

        if ($table->span()->start !== $first->span->start || $table->span()->end() !== $last->span->end()) {
            return null;
        }

        $indent = $source->slice(ByteSpan::between($first->span->start, $first->contentSpan()->start));

        if (str_contains($indent, "\t") || !$this->hasUniformLayout($source, $lines, $indent)) {
            return null;
        }

        $terminator = $this->terminator($lines);
        $border = $indent.implode('  ', array_map(
            static fn (int $width): string => str_repeat('=', $width),
            $widths,
        ));
        $replacementLines = [$border];
        $headCount = \count($table->head);

        foreach ($content as $index => $row) {
            $replacementLines[] = $this->renderRow($row, $widths, $indent);

            if ($headCount > 0 && $index + 1 === $headCount) {
                $replacementLines[] = $border;
            }
        }

        $replacementLines[] = $border;
        $replacement = implode($terminator, $replacementLines);

        if ($source->slice($table->span()) === $replacement) {
            return null;
        }

        return new SourcePatch($table->span(), $replacement);
    }

    private function stableCellText(TableCell $cell, Source $source): ?string
    {
        $children = $cell->children();

        if (
            1 !== $cell->colspan
            || 1 !== $cell->rowspan
            || 1 !== \count($children)
            || !$children[0] instanceof Paragraph
            || $children[0]->span()->start !== $cell->span()->start
            || $children[0]->span()->end() !== $cell->span()->end()
        ) {
            return null;
        }

        $text = $source->slice($cell->span());

        if (
            '' === $text
            || trim($text, " \t\v\f") !== $text
            || str_contains($text, "\n")
            || str_contains($text, "\r")
            || str_contains($text, "\t")
        ) {
            return null;
        }

        return $text;
    }

    private function rowOccupiesOneLine(TableRow $row, Source $source): bool
    {
        $start = $this->lineContaining($source, $row->span()->start);
        $end = $this->lineContaining($source, max($row->span()->start, $row->span()->end() - 1));

        return null !== $start && null !== $end && $start->index === $end->index;
    }

    /**
     * @param list<string> $content
     * @param list<int>    $widths
     */
    private function renderRow(array $content, array $widths, string $indent): string
    {
        $row = $indent;
        $last = \count($content) - 1;

        foreach ($content as $index => $text) {
            $row .= $text;

            if ($index < $last) {
                $row .= str_repeat(' ', $widths[$index] - ($this->displayWidth($text) ?? 0)).'  ';
            }
        }

        return $row;
    }

    /**
     * @return list<Line>
     */
    private function linesForSpan(Source $source, ByteSpan $span): array
    {
        $lines = [];

        foreach ($source->lines() as $line) {
            if ($line->span->end() < $span->start) {
                continue;
            }

            if ($line->span->start > $span->end()) {
                break;
            }

            if ($line->span->start < $span->end() && $line->span->end() > $span->start) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param list<Line> $lines
     */
    private function hasUniformLayout(Source $source, array $lines, string $indent): bool
    {
        $lastIndex = \count($lines) - 1;
        $terminator = $lines[0]->terminator;

        foreach ($lines as $index => $line) {
            $lineIndent = $source->slice(ByteSpan::between($line->span->start, $line->contentSpan()->start));

            if ($line->isBlank() || $lineIndent !== $indent) {
                return false;
            }

            if ($index < $lastIndex && ('' === $terminator || $line->terminator !== $terminator)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Line> $lines
     */
    private function terminator(array $lines): string
    {
        foreach ($lines as $line) {
            if ('' !== $line->terminator) {
                return $line->terminator;
            }
        }

        return "\n";
    }

    private function lineContaining(Source $source, int $offset): ?Line
    {
        foreach ($source->lines() as $line) {
            if ($offset >= $line->span->start && $offset <= $line->span->end()) {
                return $line;
            }
        }

        return null;
    }

    private function displayWidth(string $text): ?int
    {
        if (1 !== preg_match('//u', $text)) {
            return null;
        }

        if (1 === preg_match('/[\p{M}\x{200D}\x{1F1E6}-\x{1F1FF}\x{1F3FB}-\x{1F3FF}]/u', $text)) {
            return null;
        }

        $unicode = 1 === preg_match('/[^\x00-\x7F]/', $text);

        if ($unicode && !\function_exists('mb_strwidth')) {
            return null;
        }

        return $unicode ? mb_strwidth($text, 'UTF-8') : \strlen($text);
    }

    /**
     * @return array{class: class-string<Node>, properties: array<string, mixed>, children: list<mixed>}
     */
    private function semanticShape(Node $node): array
    {
        $properties = [];

        foreach (get_object_vars($node) as $name => $value) {
            if (!\is_string($name) || $value instanceof Node || $value instanceof ByteSpan) {
                continue;
            }

            if ($node instanceof Table && 'columnWidths' === $name) {
                continue;
            }

            if (\is_array($value) && $this->containsNodeOrSpan($value)) {
                continue;
            }

            $properties[$name] = $this->normalizeValue($value);
        }

        if ($node instanceof Table) {
            $properties['headRows'] = \count($node->head);
        }

        $children = [];

        if ($node instanceof ContainerNode) {
            foreach ($node->children() as $child) {
                $children[] = $this->semanticShape($child);
            }
        }

        return [
            'class' => $node::class,
            'properties' => $properties,
            'children' => $children,
        ];
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private function containsNodeOrSpan(array $values): bool
    {
        foreach ($values as $value) {
            if ($value instanceof Node || $value instanceof ByteSpan) {
                return true;
            }

            if (\is_array($value) && $this->containsNodeOrSpan($value)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return [$value::class, $value->value];
        }

        if ($value instanceof \UnitEnum) {
            return [$value::class, $value->name];
        }

        if (\is_array($value)) {
            return array_map($this->normalizeValue(...), $value);
        }

        return $value;
    }

    private static function characterLength(string $text): int
    {
        $count = preg_match_all('/./su', $text);

        return false === $count ? \strlen($text) : $count;
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function problemSignature(ProblemReport $problems): array
    {
        $signature = [];

        foreach ($problems as $problem) {
            $signature[] = [$problem->severity->value, $problem->code, $problem->message];
        }

        return $signature;
    }
}
