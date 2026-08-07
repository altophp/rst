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

namespace Alto\Rst\Tests\Convert\Support;

use Alto\Rst\Convert\Markdown\MarkdownReader;
use Alto\Rst\Convert\Markdown\MdBlockQuote;
use Alto\Rst\Convert\Markdown\MdCode;
use Alto\Rst\Convert\Markdown\MdCodeBlock;
use Alto\Rst\Convert\Markdown\MdEmphasis;
use Alto\Rst\Convert\Markdown\MdHeading;
use Alto\Rst\Convert\Markdown\MdHtmlBlock;
use Alto\Rst\Convert\Markdown\MdImage;
use Alto\Rst\Convert\Markdown\MdInlineHtml;
use Alto\Rst\Convert\Markdown\MdLink;
use Alto\Rst\Convert\Markdown\MdList;
use Alto\Rst\Convert\Markdown\MdListItem;
use Alto\Rst\Convert\Markdown\MdNode;
use Alto\Rst\Convert\Markdown\MdParagraph;
use Alto\Rst\Convert\Markdown\MdStrong;
use Alto\Rst\Convert\Markdown\MdTable;
use Alto\Rst\Convert\Markdown\MdTableRow;
use Alto\Rst\Convert\Markdown\MdText;
use Alto\Rst\Convert\Markdown\MdThematicBreak;

/**
 * Reduces a Markdown tree to a nested-array semantic shape for round-trip
 * comparison: node kinds, link destinations and styles, normalized text.
 */
final readonly class MdShape
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function of(string $markdown): array
    {
        $document = new MarkdownReader()->read($markdown);
        $definitions = [];

        foreach ($document->linkReferenceDefinitions as $definition) {
            $definitions[$definition->normalizedLabel] ??= $definition->url;
        }

        ksort($definitions);

        return [
            'body' => self::nodes($document->children()),
            'definitions' => $definitions,
        ];
    }

    /**
     * @param list<MdNode> $nodes
     *
     * @return list<mixed>
     */
    private static function nodes(array $nodes): array
    {
        $shapes = [];

        foreach ($nodes as $node) {
            $shapes[] = self::node($node);
        }

        return $shapes;
    }

    private static function node(MdNode $node): mixed
    {
        return match (true) {
            $node instanceof MdHeading => ['h', $node->level, self::inline($node->children())],
            $node instanceof MdParagraph => ['p', self::inline($node->children())],
            $node instanceof MdCodeBlock => ['code', self::language($node), rtrim($node->content, "\n")],
            $node instanceof MdBlockQuote => ['quote', self::nodes($node->children())],
            $node instanceof MdList => ['list', $node->ordered, $node->ordered ? ($node->delimiter->value ?? '.') : ($node->bulletMarker ?? '-'), $node->start, array_map(static fn (MdListItem $item): array => self::nodes($item->children()), $node->children())],
            $node instanceof MdTable => ['table', self::row($node->header), array_map(self::row(...), $node->rows())],
            $node instanceof MdThematicBreak => ['hr'],
            $node instanceof MdHtmlBlock => ['html', trim($node->content)],
            default => [$node::class],
        };
    }

    private static function language(MdCodeBlock $code): string
    {
        $info = trim($code->infoString ?? '');

        if ('' === $info) {
            return '';
        }

        $words = preg_split('/\s+/', $info);
        $words = false === $words || [] === $words ? [$info] : $words;

        return $words[0];
    }

    /**
     * @return list<mixed>
     */
    private static function row(MdTableRow $row): array
    {
        $cells = [];

        foreach ($row->children() as $cell) {
            $cells[] = self::inline($cell->children());
        }

        return $cells;
    }

    /**
     * @param list<MdNode> $nodes
     *
     * @return list<mixed>
     */
    private static function inline(array $nodes): array
    {
        $shapes = [];
        $buffer = '';

        $flush = static function () use (&$shapes, &$buffer): void {
            $normalized = trim((string) preg_replace('/\s+/', ' ', $buffer));

            if ('' !== $normalized) {
                $shapes[] = ['t', $normalized];
            }

            $buffer = '';
        };

        foreach ($nodes as $node) {
            if ($node instanceof MdText) {
                $buffer .= $node->text;

                continue;
            }

            $flush();

            $shapes[] = match (true) {
                $node instanceof MdEmphasis => ['em', self::inline($node->children())],
                $node instanceof MdStrong => ['strong', self::inline($node->children())],
                $node instanceof MdCode => ['lit', $node->text],
                $node instanceof MdLink => ['link', $node->style->name, trim((string) preg_replace('/\s+/', ' ', self::plain($node->children()))), $node->url],
                $node instanceof MdImage => ['img', self::plain($node->children()), $node->url],
                $node instanceof MdInlineHtml => ['ihtml', trim($node->content)],
                default => [$node::class],
            };
        }

        $flush();

        return $shapes;
    }

    /**
     * @param list<MdNode> $nodes
     */
    private static function plain(array $nodes): string
    {
        $text = '';

        foreach ($nodes as $node) {
            $text .= match (true) {
                $node instanceof MdText => $node->text,
                $node instanceof MdCode => $node->text,
                $node instanceof MdEmphasis,
                $node instanceof MdStrong,
                $node instanceof MdLink,
                $node instanceof MdImage => self::plain($node->children()),
                default => '',
            };
        }

        return $text;
    }
}
