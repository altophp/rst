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

use Alto\Rst\Convert\ConversionIssue;
use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\ConversionReport;
use Alto\Rst\Convert\ConversionResult;
use Alto\Rst\Convert\LinkStyle;
use Alto\Rst\Convert\Markdown\MdBlockQuote;
use Alto\Rst\Convert\Markdown\MdCode;
use Alto\Rst\Convert\Markdown\MdCodeBlock;
use Alto\Rst\Convert\Markdown\MdDocument;
use Alto\Rst\Convert\Markdown\MdEmphasis;
use Alto\Rst\Convert\Markdown\MdHeading;
use Alto\Rst\Convert\Markdown\MdHtmlBlock;
use Alto\Rst\Convert\Markdown\MdImage;
use Alto\Rst\Convert\Markdown\MdInlineHtml;
use Alto\Rst\Convert\Markdown\MdLink;
use Alto\Rst\Convert\Markdown\MdLinkStyle;
use Alto\Rst\Convert\Markdown\MdList;
use Alto\Rst\Convert\Markdown\MdListItem;
use Alto\Rst\Convert\Markdown\MdNode;
use Alto\Rst\Convert\Markdown\MdParagraph;
use Alto\Rst\Convert\Markdown\MdStrong;
use Alto\Rst\Convert\Markdown\MdTable;
use Alto\Rst\Convert\Markdown\MdTableAlignment;
use Alto\Rst\Convert\Markdown\MdTableRow;
use Alto\Rst\Convert\Markdown\MdText;
use Alto\Rst\Convert\Markdown\MdThematicBreak;

/**
 * Walks the Markdown model and writes reStructuredText.
 *
 * The inverse of MarkdownWriter: ATX and setext headings become adornment
 * styles in level order, fenced code with a language becomes a code-block
 * directive, GitHub alerts become admonitions, reference links become RST
 * targets, and pipe tables become simple tables. Constructs without an
 * RST equivalent degrade to a comment placeholder plus a report entry.
 *
 * @internal consumed through Alto\Rst\Convert\MarkdownToRst
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class RstWriter
{
    /**
     * @var array<string, string> GitHub alert name to admonition directive
     */
    private const array ALERT_DIRECTIVES = [
        'NOTE' => 'note',
        'TIP' => 'tip',
        'IMPORTANT' => 'important',
        'WARNING' => 'warning',
        'CAUTION' => 'caution',
    ];

    /**
     * @var array<string, string> blockquote label to admonition directive
     */
    private const array LABEL_DIRECTIVES = [
        'note' => 'note',
        'tip' => 'tip',
        'important' => 'important',
        'warning' => 'warning',
        'caution' => 'caution',
    ];

    /**
     * @var array<string, string> version label prefix to directive name
     */
    private const array VERSION_DIRECTIVES = [
        'New in version' => 'versionadded',
        'Changed in version' => 'versionchanged',
        'Deprecated since version' => 'deprecated',
    ];

    private const string SIMPLE_NAME = '/^[0-9A-Za-z]+(?:[-._+:][0-9A-Za-z]+)*$/';

    /**
     * @var list<ConversionIssue>
     */
    private array $issues = [];

    /**
     * @var list<array{label: string, url: string}>
     */
    private array $extraTargets = [];

    /**
     * @var array<string, string>
     */
    private array $documentTargets = [];

    public function __construct(
        private readonly ConversionOptions $options,
    ) {}

    public function write(MdDocument $document): ConversionResult
    {
        foreach ($document->linkReferenceDefinitions as $definition) {
            $this->documentTargets[$definition->normalizedLabel] ??= $definition->url;
        }

        $blocks = $this->blocks($document->children());
        $definitions = $this->definitionLines($document);

        if ([] !== $definitions) {
            $blocks[] = implode("\n", $definitions);
        }

        $output = implode("\n\n", $blocks);

        return new ConversionResult(
            '' === $output ? '' : $output . "\n",
            new ConversionReport($this->issues),
        );
    }

    /**
     * @return list<string>
     */
    private function definitionLines(MdDocument $document): array
    {
        $lines = [];
        $seen = [];

        foreach ($document->linkReferenceDefinitions as $definition) {
            if (isset($seen[$definition->normalizedLabel])) {
                continue;
            }

            $seen[$definition->normalizedLabel] = true;

            if (null !== $definition->title) {
                $this->issue(ConversionIssue::lossy(
                    'md:link-title',
                    sprintf('Link definition "%s" carries a title; RST targets cannot.', $definition->label),
                    $definition->span,
                ));
            }

            $lines[] = $this->targetLine($definition->label, $definition->url);
        }

        foreach ($this->extraTargets as $target) {
            $key = strtolower(trim((string) preg_replace('/\s+/', ' ', $target['label'])));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $lines[] = $this->targetLine($target['label'], $target['url']);
        }

        return $lines;
    }

    private function targetLine(string $label, string $url): string
    {
        $name = 1 === preg_match(self::SIMPLE_NAME, $label) ? $label : '`' . $label . '`';

        return '.. _' . $name . ': ' . $url;
    }

    /**
     * @param list<MdNode> $nodes
     *
     * @return list<string>
     */
    private function blocks(array $nodes): array
    {
        $blocks = [];

        foreach ($nodes as $node) {
            foreach ($this->blocksFor($node) as $block) {
                if ('' !== $block) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    private function blocksFor(MdNode $node): array
    {
        return match (true) {
            $node instanceof MdHeading => [$this->headingBlock($node)],
            $node instanceof MdParagraph => [$this->paragraphBlock($node)],
            $node instanceof MdCodeBlock => [$this->codeBlock($node)],
            $node instanceof MdBlockQuote => [$this->blockQuoteBlock($node)],
            $node instanceof MdList => [$this->listBlock($node)],
            $node instanceof MdTable => [$this->tableBlock($node)],
            $node instanceof MdThematicBreak => ['----'],
            $node instanceof MdHtmlBlock => [$this->htmlBlock($node)],
            default => $this->fallbackBlocks($node),
        };
    }

    private function headingBlock(MdHeading $heading): string
    {
        $title = $this->renderInline($heading->children());
        $title = '' === $title ? '\\ ' : $title;
        $adornment = $this->options->adornmentFor($heading->level);

        return $title . "\n" . str_repeat($adornment, max(1, \strlen($title)));
    }

    private function paragraphBlock(MdParagraph $paragraph): string
    {
        $children = $paragraph->children();

        if (1 === \count($children) && $children[0] instanceof MdImage) {
            return $this->imageDirective($children[0]);
        }

        return $this->escapeLineStart($this->renderInline($children));
    }

    private function imageDirective(MdImage $image): string
    {
        $alt = $this->plainText($image->children());
        $block = '.. image:: ' . $image->url;
        $dropped = [];

        if (null !== $image->title) {
            $dropped[] = 'title';
        }
        if (MdLinkStyle::Reference === $image->style) {
            $dropped[] = 'reference style and label';
        }

        if ([] !== $dropped) {
            $this->issue(ConversionIssue::lossy(
                'md:image-metadata',
                'Image metadata dropped: ' . implode(', ', $dropped) . '.',
                $image->span(),
            ));
        }

        if ('' !== $alt) {
            $block .= "\n" . $this->options->indent() . ':alt: ' . $alt;
        }

        return $block;
    }

    private function codeBlock(MdCodeBlock $code): string
    {
        $content = rtrim($code->content, "\n");
        $content = implode("\n", array_map(rtrim(...), Lines::split($content)));
        $info = trim($code->infoString ?? '');

        if ('' === $info) {
            if ('' === $content) {
                return '';
            }

            return "::\n\n" . Lines::indent($content, $this->options->indent());
        }

        $words = preg_split('/\s+/', $info);
        $words = false === $words || [] === $words ? [$info] : $words;
        $language = $words[0];

        if (\count($words) > 1) {
            $this->issue(ConversionIssue::lossy(
                'md:info-string',
                sprintf('Fence info "%s" reduced to its language, "%s".', $info, $language),
                $code->span(),
            ));
        }

        $directive = '.. ' . $this->options->codeBlockDirective . ':: ' . $language;

        if ('' === $content) {
            return $directive;
        }

        return $directive . "\n\n" . Lines::indent($content, $this->options->indent());
    }

    private function blockQuoteBlock(MdBlockQuote $quote): string
    {
        $children = $quote->children();
        $first = $children[0] ?? null;

        if ($first instanceof MdParagraph) {
            $admonition = $this->admonitionFromQuote($first, \array_slice($children, 1));

            if (null !== $admonition) {
                return $admonition;
            }
        }

        $content = implode("\n\n", $this->blocks($children));

        return '' === $content ? '' : Lines::indent($content, $this->options->indent());
    }

    /**
     * Recognizes the three blockquote shapes the Markdown side writes for
     * RST directives: a GitHub alert marker, a bold admonition label, and
     * a bold version line.
     *
     * @param list<MdNode> $rest
     */
    private function admonitionFromQuote(MdParagraph $first, array $rest): ?string
    {
        $items = $first->children();
        $head = $items[0] ?? null;

        if ($head instanceof MdText && 1 === preg_match('/^\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*(.*)$/s', $head->text, $matches)) {
            $directive = self::ALERT_DIRECTIVES[$matches[1]];
            $remainder = $matches[2];
            $bodyItems = '' === $remainder
                ? \array_slice($items, 1)
                : [new MdText($head->span(), $remainder), ...\array_slice($items, 1)];

            return $this->directiveBlock('.. ' . $directive . '::', $this->quoteBody($bodyItems, $rest));
        }

        if ($head instanceof MdStrong) {
            $label = trim($this->plainText($head->children()));

            foreach (self::VERSION_DIRECTIVES as $prefix => $directive) {
                if (1 === preg_match('/^' . preg_quote($prefix, '/') . '\s+(\S+)$/', $label, $matches)) {
                    return $this->directiveBlock(
                        '.. ' . $directive . ':: ' . $matches[1],
                        $this->quoteBody($this->trimLeadingSpace(\array_slice($items, 1)), $rest),
                    );
                }
            }

            $directive = self::LABEL_DIRECTIVES[strtolower($label)] ?? null;

            if (null !== $directive) {
                return $this->directiveBlock(
                    '.. ' . $directive . '::',
                    $this->quoteBody($this->trimLeadingSpace(\array_slice($items, 1)), $rest),
                );
            }
        }

        return null;
    }

    /**
     * @param list<MdNode> $firstParagraphRest
     * @param list<MdNode> $rest
     *
     * @return list<string>
     */
    private function quoteBody(array $firstParagraphRest, array $rest): array
    {
        $blocks = [];

        if ([] !== $firstParagraphRest) {
            $line = $this->escapeLineStart($this->renderInline($firstParagraphRest));

            if ('' !== $line) {
                $blocks[] = $line;
            }
        }

        return [...$blocks, ...$this->blocks($rest)];
    }

    /**
     * Drops the leading whitespace an inline run keeps after a consumed
     * bold label.
     *
     * @param list<MdNode> $items
     *
     * @return list<MdNode>
     */
    private function trimLeadingSpace(array $items): array
    {
        $head = $items[0] ?? null;

        if ($head instanceof MdText) {
            $trimmed = ltrim($head->text);

            if ('' === $trimmed) {
                return \array_slice($items, 1);
            }

            return [new MdText($head->span(), $trimmed), ...\array_slice($items, 1)];
        }

        return $items;
    }

    /**
     * @param list<string> $body
     */
    private function directiveBlock(string $head, array $body): string
    {
        $content = implode("\n\n", $body);

        if ('' === $content) {
            return $head;
        }

        return $head . "\n\n" . Lines::indent($content, $this->options->indent());
    }

    private function listBlock(MdList $list): string
    {
        $items = [];
        $number = max(1, $list->start);
        $separator = $this->listSeparator($list);

        foreach ($list->children() as $item) {
            if ($list->ordered) {
                $marker = $number . ($list->delimiter->value ?? '.') . ' ';
                ++$number;
            } else {
                $marker = ($list->bulletMarker ?? $this->options->bulletMarker) . ' ';
            }

            $items[] = $this->listItemBlock($item, $marker);
        }

        return implode($separator, $items);
    }

    private function listItemBlock(MdListItem $item, string $marker): string
    {
        $content = implode("\n\n", $this->blocks($item->children()));

        if ('' === $content) {
            return rtrim($marker);
        }

        return Lines::prefix($content, $marker, str_repeat(' ', \strlen($marker)));
    }

    private function listSeparator(MdList $list): string
    {
        if (!$list->tight) {
            return "\n\n";
        }

        foreach ($list->children() as $item) {
            $children = $item->children();

            if (1 !== \count($children) || !$children[0] instanceof MdParagraph) {
                return "\n\n";
            }
        }

        return "\n";
    }

    private function tableBlock(MdTable $table): string
    {
        foreach ($table->alignments as $alignment) {
            if (MdTableAlignment::None !== $alignment) {
                $this->issue(ConversionIssue::lossy(
                    'md:table-alignment',
                    'Simple tables cannot express column alignment.',
                    $table->span(),
                ));

                break;
            }
        }

        $header = $this->tableRowTexts($table->header);
        $rows = array_map($this->tableRowTexts(...), $table->rows());
        $columns = max(1, \count($header), ...array_map(\count(...), [[], ...$rows]));

        $widths = [];

        for ($index = 0; $index < $columns; ++$index) {
            $width = 1;

            foreach ([$header, ...$rows] as $cells) {
                $width = max($width, \strlen($cells[$index] ?? ''));
            }

            $widths[] = $width;
        }

        $border = implode('  ', array_map(static fn(int $width): string => str_repeat('=', $width), $widths));
        $lines = [$border, $this->tableRowLine($header, $widths), $border];

        if ([] === $rows) {
            $this->issue(ConversionIssue::lossy(
                'md:table-headless',
                'A table without body rows loses its header distinction in a simple table.',
                $table->span(),
            ));

            return implode("\n", [$border, $this->tableRowLine($header, $widths), $border]);
        }

        foreach ($rows as $cells) {
            $lines[] = $this->tableRowLine($cells, $widths);
        }

        $lines[] = $border;

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function tableRowTexts(MdTableRow $row): array
    {
        $cells = [];

        foreach ($row->children() as $cell) {
            $text = str_replace("\n", ' ', $this->renderInline($cell->children()));
            $cells[] = $this->escapeLineStart($text);
        }

        return $cells;
    }

    /**
     * @param list<string> $cells
     * @param list<int>    $widths
     */
    private function tableRowLine(array $cells, array $widths): string
    {
        $first = $cells[0] ?? '';

        if ('' === trim($first)) {
            $this->issue(ConversionIssue::lossy(
                'md:table-empty-cell',
                'An empty first cell reads as a row continuation in a simple table.',
                null,
            ));
        }

        $padded = [];

        foreach ($widths as $index => $width) {
            $padded[] = str_pad($cells[$index] ?? '', $width);
        }

        return rtrim(implode('  ', $padded));
    }

    private function htmlBlock(MdHtmlBlock $html): string
    {
        $content = trim($html->content);

        if (1 === preg_match('/^<!--(.*)-->$/s', $content, $matches)) {
            return $this->commentBlock(trim($matches[1]));
        }

        if (1 === preg_match('/^<a id="([A-Za-z0-9._:-]+)"><\/a>$/', $content, $matches)) {
            return '.. _' . $matches[1] . ':';
        }

        $this->issue(ConversionIssue::unsupported(
            'md:html-block',
            'Raw HTML blocks have no RST equivalent; kept as a comment.',
            $html->span(),
        ));

        return "..\n" . Lines::indent("raw HTML block:\n\n" . $content, '   ');
    }

    private function commentBlock(string $text): string
    {
        if ('' === $text) {
            return '..';
        }

        if (!str_contains($text, "\n")) {
            return '.. ' . $text;
        }

        return "..\n" . Lines::indent($text, '   ');
    }

    /**
     * @return list<string>
     */
    private function fallbackBlocks(MdNode $node): array
    {
        $class = $node::class;
        $short = false === ($position = strrpos($class, '\\')) ? $class : substr($class, $position + 1);

        $this->issue(ConversionIssue::unsupported(
            'md:' . strtolower($short),
            sprintf('Markdown node "%s" has no RST mapping.', $short),
            $node->span(),
        ));

        return ['.. ' . $short . ' dropped by markdown-to-rst'];
    }

    /**
     * @param list<MdNode> $nodes
     */
    private function renderInline(array $nodes): string
    {
        $rst = '';

        foreach ($nodes as $node) {
            $rst .= $this->renderInlineNode($node);
        }

        return $rst;
    }

    private function renderInlineNode(MdNode $node): string
    {
        return match (true) {
            $node instanceof MdText => $this->escapeText($node->text),
            $node instanceof MdEmphasis => '*' . $this->markupContent($node->children()) . '*',
            $node instanceof MdStrong => '**' . $this->markupContent($node->children()) . '**',
            $node instanceof MdCode => $this->literalSpan($node->text),
            $node instanceof MdLink => $this->renderLink($node),
            $node instanceof MdImage => $this->renderInlineImage($node),
            $node instanceof MdInlineHtml => $this->renderInlineHtml($node),
            default => '',
        };
    }

    /**
     * RST inline markup does not nest; nested markup flattens to text.
     *
     * @param list<MdNode> $children
     */
    private function markupContent(array $children): string
    {
        foreach ($children as $child) {
            if (!$child instanceof MdText) {
                $this->issue(ConversionIssue::lossy(
                    'md:nested-markup',
                    'RST inline markup cannot nest; inner markup flattened to text.',
                    $child->span(),
                ));

                return $this->escapeText($this->plainText($children));
            }
        }

        return $this->escapeText($this->plainText($children));
    }

    private function literalSpan(string $text): string
    {
        if (str_contains($text, '``')) {
            $this->issue(ConversionIssue::lossy(
                'md:code-span',
                'A code span containing "``" cannot survive as an RST inline literal.',
                null,
            ));
            $text = str_replace('``', '` `', $text);
        }

        if (str_starts_with($text, '`') || str_ends_with($text, '`')) {
            $text = ' ' . $text . ' ';
        }

        return '``' . $text . '``';
    }

    private function renderLink(MdLink $link): string
    {
        $text = $this->linkText($link->children());

        if (null !== $link->title) {
            $this->issue(ConversionIssue::lossy(
                'md:link-title',
                sprintf('Link "%s" carries a title; RST links cannot.', $text),
                $link->span(),
            ));
        }

        if (MdLinkStyle::Reference === $link->style && LinkStyle::Inline !== $this->options->linkStyle) {
            return $this->renderReferenceLink($link, $text);
        }

        if (LinkStyle::Reference === $this->options->linkStyle && $this->registerTarget($text, $link->url)) {
            return $this->namedReference($text);
        }

        if ($text === $link->url) {
            return $link->url;
        }

        return '`' . $text . ' <' . $link->url . '>`_';
    }

    private function renderReferenceLink(MdLink $link, string $text): string
    {
        $label = $link->referenceLabel ?? $text;
        $sameName = strtolower(trim((string) preg_replace('/\s+/', ' ', $label)))
            === strtolower(trim((string) preg_replace('/\s+/', ' ', $text)));

        if ($sameName) {
            return $this->namedReference($text);
        }

        if ($this->registerTarget($text, $link->url)) {
            $this->issue(ConversionIssue::approximated(
                'md:reference-label',
                sprintf('Reference label "%s" re-keyed to the link text "%s".', $label, $text),
                $link->span(),
            ));

            return $this->namedReference($text);
        }

        $this->issue(ConversionIssue::lossy(
            'md:reference-label',
            sprintf('Reference label "%s" conflicts with an existing target; the link was inlined.', $label),
            $link->span(),
        ));

        return '`' . $text . ' <' . $link->url . '>`_';
    }

    private function namedReference(string $text): string
    {
        if (1 === preg_match(self::SIMPLE_NAME, $text)) {
            return $text . '_';
        }

        return '`' . $text . '`_';
    }

    private function registerTarget(string $label, string $url): bool
    {
        $key = strtolower(trim((string) preg_replace('/\s+/', ' ', $label)));

        if (isset($this->documentTargets[$key])) {
            return $this->documentTargets[$key] === $url;
        }

        foreach ($this->extraTargets as $target) {
            if (strtolower(trim((string) preg_replace('/\s+/', ' ', $target['label']))) === $key) {
                return $target['url'] === $url;
            }
        }

        $this->extraTargets[] = ['label' => $label, 'url' => $url];

        return true;
    }

    /**
     * Link text is a name in RST; markup inside it cannot survive.
     *
     * @param list<MdNode> $children
     */
    private function linkText(array $children): string
    {
        foreach ($children as $child) {
            if (!$child instanceof MdText) {
                $this->issue(ConversionIssue::lossy(
                    'md:nested-markup',
                    'RST link text cannot carry inline markup; it was flattened.',
                    $child->span(),
                ));

                break;
            }
        }

        return trim($this->plainText($children));
    }

    private function renderInlineImage(MdImage $image): string
    {
        $this->issue(ConversionIssue::lossy(
            'md:inline-image',
            'RST has no inline image; the URL and image metadata were dropped while the alt text was kept.',
            $image->span(),
        ));

        return $this->escapeText($this->plainText($image->children()));
    }

    private function renderInlineHtml(MdInlineHtml $html): string
    {
        $content = trim($html->content);

        if (1 === preg_match('/^<br\s*\/?>$/i', $content)) {
            $this->issue(ConversionIssue::lossy(
                'md:inline-html',
                'A "<br>" collapsed to a space.',
                $html->span(),
            ));

            return ' ';
        }

        if (str_starts_with($content, '<!--')) {
            $this->issue(ConversionIssue::lossy(
                'md:inline-html',
                'An inline HTML comment was dropped.',
                $html->span(),
            ));

            return '';
        }

        $this->issue(ConversionIssue::lossy(
            'md:inline-html',
            sprintf('Inline HTML "%s" kept as plain text.', $content),
            $html->span(),
        ));

        return $this->escapeText($content);
    }

    /**
     * Flattens inline nodes to their raw text, markup dropped.
     *
     * @param list<MdNode> $nodes
     */
    private function plainText(array $nodes): string
    {
        $text = '';

        foreach ($nodes as $node) {
            $text .= match (true) {
                $node instanceof MdText => $node->text,
                $node instanceof MdCode => $node->text,
                $node instanceof MdInlineHtml => '',
                $node instanceof MdEmphasis,
                $node instanceof MdStrong,
                $node instanceof MdLink,
                $node instanceof MdImage => $this->plainText($node->children()),
                default => '',
            };
        }

        return $text;
    }

    /**
     * Backslash-escapes the characters that would become RST inline
     * markup. Underscores stay bare when a word continues after them
     * ("snake_case"); a trailing underscore would read as a reference.
     */
    private function escapeText(string $text): string
    {
        $escaped = (string) preg_replace('/([\\\\`*|])/', '\\\\$1', $text);

        return (string) preg_replace('/_(?![A-Za-z0-9])/', '\\\\_', $escaped);
    }

    /**
     * Escapes a line's first characters when they would reparse as an RST
     * block construct.
     */
    private function escapeLineStart(string $line): string
    {
        if (1 === preg_match('/^(\d{1,9}|#)([.)])(\s|$)/', $line, $matches)) {
            return $matches[1] . '\\' . $matches[2] . substr($line, \strlen($matches[1]) + 1);
        }

        if (1 === preg_match('/^([-+])(\s|$)/', $line)) {
            return '\\' . $line;
        }

        if (1 === preg_match('/^\.\.(\s|$)/', $line)) {
            return '\\' . $line;
        }

        if (1 === preg_match('/^__(\s|$)/', $line)) {
            return '\\' . $line;
        }

        if (str_starts_with($line, ':')) {
            return '\\' . $line;
        }

        if (1 === preg_match('/^([!-\/:-@\[-`{-~])\1{3,}$/', $line)) {
            return '\\' . $line;
        }

        return $line;
    }

    private function issue(ConversionIssue $issue): void
    {
        $this->issues[] = $issue;
    }
}
