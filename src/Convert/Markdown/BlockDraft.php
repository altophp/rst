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

namespace Alto\Rst\Convert\Markdown;

use Alto\Rst\Source\ByteSpan;

/**
 * A block under construction while MarkdownBlockParser scans the document.
 *
 * Nodes with inline content (headings, paragraphs, table cells) cannot be
 * built as their final immutable form yet: link reference definitions can
 * be declared anywhere in the document, including after their first use, so
 * inline parsing only happens once the whole document has been scanned and
 * every definition is known. A draft keeps the raw text and defers to
 * finalize().
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class BlockDraft
{
    /**
     * @param list<self>|null                                $children
     * @param list<MdTableAlignment>|null                    $alignments
     * @param list<array{0: ByteSpan, 1: string}>|null       $headerCells
     * @param list<list<array{0: ByteSpan, 1: string}>>|null $rows
     */
    private function __construct(
        private readonly ByteSpan $span,
        private readonly string $kind,
        private readonly ?MdNode $finalNode = null,
        private readonly ?string $rawText = null,
        private readonly ?int $baseOffset = null,
        private readonly ?int $level = null,
        private readonly ?MdHeadingStyle $headingStyle = null,
        private readonly ?array $children = null,
        private readonly ?bool $ordered = null,
        private readonly ?string $bulletMarker = null,
        private readonly ?MdListDelimiter $delimiter = null,
        private readonly ?int $start = null,
        private readonly ?bool $tight = null,
        private readonly ?array $alignments = null,
        private readonly ?array $headerCells = null,
        private readonly ?array $rows = null,
    ) {}

    /**
     * A block that is already fully resolved: it carries no inline content
     * that needs the document-wide reference map.
     */
    public static function final(MdNode $node): self
    {
        return new self($node->span(), 'final', finalNode: $node);
    }

    public static function paragraph(ByteSpan $span, string $rawText, int $baseOffset): self
    {
        return new self($span, 'paragraph', rawText: $rawText, baseOffset: $baseOffset);
    }

    public static function heading(ByteSpan $span, int $level, MdHeadingStyle $style, string $rawText, int $baseOffset): self
    {
        return new self($span, 'heading', rawText: $rawText, baseOffset: $baseOffset, level: $level, headingStyle: $style);
    }

    /**
     * @param list<self> $children
     */
    public static function blockQuote(ByteSpan $span, array $children): self
    {
        return new self($span, 'blockQuote', children: $children);
    }

    /**
     * @param list<self> $items
     */
    public static function list(ByteSpan $span, bool $ordered, ?string $bulletMarker, ?MdListDelimiter $delimiter, int $start, bool $tight, array $items): self
    {
        return new self($span, 'list', children: $items, ordered: $ordered, bulletMarker: $bulletMarker, delimiter: $delimiter, start: $start, tight: $tight);
    }

    /**
     * @param list<self> $children
     */
    public static function listItem(ByteSpan $span, array $children): self
    {
        return new self($span, 'listItem', children: $children);
    }

    /**
     * @param list<MdTableAlignment>                    $alignments
     * @param list<array{0: ByteSpan, 1: string}>       $headerCells
     * @param list<list<array{0: ByteSpan, 1: string}>> $rows
     */
    public static function table(ByteSpan $span, array $alignments, array $headerCells, array $rows): self
    {
        return new self($span, 'table', alignments: $alignments, headerCells: $headerCells, rows: $rows);
    }

    public function span(): ByteSpan
    {
        return $this->span;
    }

    public function finalize(MarkdownInlineParser $inline): MdNode
    {
        return match ($this->kind) {
            'final' => $this->finalNode ?? throw new \LogicException('Final draft is missing its node.'),
            'paragraph' => new MdParagraph($this->span, $inline->parse($this->requireRawText(), $this->requireBaseOffset())),
            'heading' => new MdHeading(
                $this->span,
                $this->level ?? throw new \LogicException('Heading draft is missing its level.'),
                $this->headingStyle ?? throw new \LogicException('Heading draft is missing its style.'),
                $inline->parse($this->requireRawText(), $this->requireBaseOffset()),
            ),
            'blockQuote' => new MdBlockQuote($this->span, $this->finalizeChildren($inline)),
            'listItem' => new MdListItem($this->span, $this->finalizeChildren($inline)),
            'list' => new MdList(
                $this->span,
                $this->ordered ?? throw new \LogicException('List draft is missing its ordered flag.'),
                $this->bulletMarker,
                $this->delimiter,
                $this->start ?? throw new \LogicException('List draft is missing its start value.'),
                $this->tight ?? throw new \LogicException('List draft is missing its tight flag.'),
                $this->finalizeListItems($inline),
            ),
            'table' => $this->finalizeTable($inline),
            default => throw new \LogicException(\sprintf('Unknown block draft kind "%s".', $this->kind)),
        };
    }

    /**
     * @return list<MdNode>
     */
    private function finalizeChildren(MarkdownInlineParser $inline): array
    {
        $result = [];

        foreach ($this->children ?? [] as $child) {
            $result[] = $child->finalize($inline);
        }

        return $result;
    }

    /**
     * @return list<MdListItem>
     */
    private function finalizeListItems(MarkdownInlineParser $inline): array
    {
        $items = [];

        foreach ($this->children ?? [] as $child) {
            $node = $child->finalize($inline);

            if (!$node instanceof MdListItem) {
                throw new \LogicException('List item draft did not finalize to an MdListItem.');
            }

            $items[] = $node;
        }

        return $items;
    }

    private function finalizeTable(MarkdownInlineParser $inline): MdTable
    {
        $alignments = $this->alignments ?? throw new \LogicException('Table draft is missing its alignments.');
        $headerCells = $this->headerCells ?? throw new \LogicException('Table draft is missing its header cells.');
        $rows = $this->rows ?? throw new \LogicException('Table draft is missing its rows.');

        $header = new MdTableRow($this->rowSpan($headerCells), $this->finalizeCells($headerCells, $inline));
        $bodyRows = [];

        foreach ($rows as $rowCells) {
            $bodyRows[] = new MdTableRow($this->rowSpan($rowCells), $this->finalizeCells($rowCells, $inline));
        }

        return new MdTable($this->span, $alignments, $header, $bodyRows);
    }

    /**
     * @param list<array{0: ByteSpan, 1: string}> $cells
     */
    private function rowSpan(array $cells): ByteSpan
    {
        if ([] === $cells) {
            return $this->span;
        }

        $first = $cells[0][0];
        $last = $cells[\count($cells) - 1][0];

        return ByteSpan::between($first->start, $last->end());
    }

    /**
     * @param list<array{0: ByteSpan, 1: string}> $cells
     *
     * @return list<MdTableCell>
     */
    private function finalizeCells(array $cells, MarkdownInlineParser $inline): array
    {
        $result = [];

        foreach ($cells as [$span, $text]) {
            $result[] = new MdTableCell($span, $inline->parse($text, $span->start));
        }

        return $result;
    }

    private function requireRawText(): string
    {
        return $this->rawText ?? throw new \LogicException('Draft is missing its raw text.');
    }

    private function requireBaseOffset(): int
    {
        return $this->baseOffset ?? throw new \LogicException('Draft is missing its base offset.');
    }
}
