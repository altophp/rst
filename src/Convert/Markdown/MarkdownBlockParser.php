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
use Alto\Rst\Source\Line;
use Alto\Rst\Source\Source;

/**
 * The recursive block scanner behind MarkdownReader.
 *
 * Container blocks (block quotes, list items) recurse by re-scanning their
 * physical lines from a shifted start byte, via Line::scan(), so every
 * nested parseBlocks() call sees indentation relative to its own container,
 * the same way Alto\Rst\Parser\BlockScanner treats RST indentation.
 *
 * Blocks with inline content are kept as BlockDraft placeholders: link
 * reference definitions can be declared anywhere in the document, including
 * after their first use, so inline parsing happens only once the whole
 * document is scanned and every definition is known (see BlockDraft).
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class MarkdownBlockParser
{
    /**
     * CommonMark's HTML block "type 6" tag names: these start a block
     * regardless of what follows on the line. Any other tag name only
     * starts a block when it is alone on the line ("type 7").
     */
    private const array HTML_BLOCK_TAGS = [
        'address', 'article', 'aside', 'base', 'basefont', 'blockquote', 'body',
        'caption', 'center', 'col', 'colgroup', 'dd', 'details', 'dialog', 'dir',
        'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form',
        'frame', 'frameset', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header',
        'hr', 'html', 'iframe', 'legend', 'li', 'link', 'main', 'menu', 'menuitem',
        'nav', 'noframes', 'ol', 'optgroup', 'option', 'p', 'param', 'pre',
        'script', 'section', 'style', 'summary', 'table', 'tbody', 'td', 'tfoot',
        'th', 'thead', 'title', 'tr', 'track', 'ul',
    ];

    /**
     * @var list<MdLinkReferenceDefinition>
     */
    private array $definitions = [];

    public function __construct(
        private readonly Source $source,
    ) {}

    public function parseDocument(): DocumentDraft
    {
        $cursor = new MdLineCursor($this->source->lines());
        $children = $this->parseBlocks($cursor);
        $span = ByteSpan::of(0, \strlen($this->source->bytes));

        return new DocumentDraft($span, $children, $this->definitions);
    }

    /**
     * @return list<BlockDraft>
     */
    private function parseBlocks(MdLineCursor $cursor): array
    {
        $blocks = [];

        while (true) {
            $cursor->skipBlankLines();

            if ($cursor->atEnd()) {
                break;
            }

            $block = $this->parseBlock($cursor);

            if (null !== $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * Parses one block starting at the cursor's current, non-blank line.
     * Returns null when the line only contributed a link reference
     * definition and nothing renders for it.
     */
    private function parseBlock(MdLineCursor $cursor): ?BlockDraft
    {
        $line = $cursor->peek();

        if (null === $line) {
            return null;
        }

        if ($line->indentWidth >= 4) {
            return $this->parseIndentedCodeBlock($cursor);
        }

        if (null !== $this->matchFence($line)) {
            return $this->parseFencedCodeBlock($cursor);
        }

        $heading = $this->tryAtxHeading($line);

        if (null !== $heading) {
            $cursor->advance();

            return $heading;
        }

        if ($this->isThematicBreak($line)) {
            $cursor->advance();
            $stripped = str_replace([' ', "\t"], '', $this->content($line));
            $span = ByteSpan::between($line->span->start, $line->span->end());

            return BlockDraft::final(new MdThematicBreak($span, $stripped[0]));
        }

        if ($this->startsBlockQuote($line)) {
            return $this->parseBlockQuote($cursor);
        }

        if (null !== ($bullet = $this->bulletMarker($line))) {
            return $this->parseList($cursor, false, $bullet, null, 1);
        }

        if (null !== ($ordered = $this->orderedMarker($line))) {
            return $this->parseList($cursor, true, null, $ordered['delimiter'], $ordered['start']);
        }

        if ($this->looksLikeHtmlBlockStart($line)) {
            return $this->parseHtmlBlock($cursor);
        }

        return $this->parseTextualBlock($cursor);
    }

    /**
     * True when a non-blank line starts a block that is allowed to
     * interrupt an open paragraph, or to break blockquote lazy
     * continuation.
     */
    private function looksLikeBlockStart(Line $line): bool
    {
        if ($line->indentWidth >= 4) {
            return false;
        }

        if (null !== $this->tryAtxHeading($line)) {
            return true;
        }

        if ($this->isThematicBreak($line)) {
            return true;
        }

        if (null !== $this->matchFence($line)) {
            return true;
        }

        if ($this->startsBlockQuote($line)) {
            return true;
        }

        if (null !== $this->bulletMarker($line)) {
            return true;
        }

        if (null !== $this->orderedMarker($line)) {
            return true;
        }

        return $this->looksLikeHtmlBlockStart($line);
    }

    private function content(Line $line): string
    {
        return $this->source->slice($line->contentSpan());
    }

    // -- ATX heading ---------------------------------------------------

    private function tryAtxHeading(Line $line): ?BlockDraft
    {
        if ($line->indentWidth > 3) {
            return null;
        }

        $content = rtrim($this->content($line), " \t");

        if (1 !== preg_match('/^(#{1,6})(?:[ \t]+(.*))?$/', $content, $m, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $level = \strlen($m[1][0]);
        $rawTextFull = isset($m[2]) ? $m[2][0] : '';
        $textOffset = isset($m[2]) ? $m[2][1] : \strlen($content);

        $rawText = $this->stripAtxClosingSequence($rawTextFull);
        $baseOffset = $line->contentSpan()->start + $textOffset;
        $span = ByteSpan::between($line->span->start, $line->span->end());

        return BlockDraft::heading($span, $level, MdHeadingStyle::Atx, $rawText, $baseOffset);
    }

    private function stripAtxClosingSequence(string $text): string
    {
        if (1 === preg_match('/^(.*?)[ \t]+#+$/', $text, $m)) {
            return rtrim($m[1], " \t");
        }

        if ('' !== $text && 1 === preg_match('/^#+$/', $text)) {
            return '';
        }

        return $text;
    }

    // -- Thematic break and setext heading ------------------------------

    private function isThematicBreak(Line $line): bool
    {
        if ($line->indentWidth > 3) {
            return false;
        }

        $stripped = str_replace([' ', "\t"], '', $this->content($line));

        if (\strlen($stripped) < 3) {
            return false;
        }

        $char = $stripped[0];

        if (!\in_array($char, ['-', '_', '*'], true)) {
            return false;
        }

        return $stripped === str_repeat($char, \strlen($stripped));
    }

    private function setextLevel(Line $line): ?int
    {
        if ($line->indentWidth > 3) {
            return null;
        }

        $content = rtrim($this->content($line), " \t");

        if ('' === $content) {
            return null;
        }

        if (1 === preg_match('/^=+$/', $content)) {
            return 1;
        }

        if (1 === preg_match('/^-+$/', $content)) {
            return 2;
        }

        return null;
    }

    // -- Code blocks -----------------------------------------------------

    /**
     * @return array{char: string, length: int, info: string}|null
     */
    private function matchFence(Line $line): ?array
    {
        if ($line->indentWidth > 3) {
            return null;
        }

        $content = $this->content($line);

        if (1 !== preg_match('/^(`{3,}|~{3,})[ \t]*(.*)$/', $content, $m)) {
            return null;
        }

        $fenceChar = $m[1][0];
        $fenceLength = \strlen($m[1]);
        $info = trim($m[2]);

        if ('`' === $fenceChar && str_contains($info, '`')) {
            return null;
        }

        return ['char' => $fenceChar, 'length' => $fenceLength, 'info' => $info];
    }

    private function isClosingFence(Line $line, string $fenceChar, int $minLength): bool
    {
        if ($line->indentWidth > 3) {
            return false;
        }

        $content = rtrim($this->content($line), " \t");

        if ('' === $content) {
            return false;
        }

        if ($content !== str_repeat($fenceChar, \strlen($content))) {
            return false;
        }

        return \strlen($content) >= $minLength;
    }

    private function parseFencedCodeBlock(MdLineCursor $cursor): BlockDraft
    {
        $first = $cursor->peek() ?? throw new \LogicException('parseFencedCodeBlock requires a current line.');
        $fence = $this->matchFence($first) ?? throw new \LogicException('Expected a fence on the current line.');
        $cursor->advance();

        $stripWidth = $first->indentWidth;
        $lines = [];
        $last = $first;

        while (null !== ($line = $cursor->peek())) {
            if ($this->isClosingFence($line, $fence['char'], $fence['length'])) {
                $last = $line;
                $cursor->advance();

                break;
            }

            $lines[] = $this->stripIndentColumns($line, $stripWidth);
            $last = $line;
            $cursor->advance();
        }

        $content = implode("\n", $lines);
        $span = ByteSpan::between($first->span->start, $last->span->end());
        $infoString = '' === $fence['info'] ? null : $fence['info'];

        return BlockDraft::final(new MdCodeBlock($span, MdCodeBlockStyle::Fenced, $content, $infoString, $fence['char'], $fence['length']));
    }

    private function parseIndentedCodeBlock(MdLineCursor $cursor): BlockDraft
    {
        $first = $cursor->peek() ?? throw new \LogicException('parseIndentedCodeBlock requires a current line.');
        $collected = [];

        while (null !== ($line = $cursor->peek())) {
            if ($line->isBlank()) {
                $collected[] = $line;
                $cursor->advance();

                continue;
            }

            if ($line->indentWidth < 4) {
                break;
            }

            $collected[] = $line;
            $cursor->advance();
        }

        while ([] !== $collected && $collected[\count($collected) - 1]->isBlank()) {
            array_pop($collected);
        }

        if ([] === $collected) {
            $collected = [$first];
        }

        $lines = array_map(
            fn(Line $line): string => $line->isBlank() ? '' : $this->stripIndentColumns($line, 4),
            $collected,
        );

        $content = implode("\n", $lines);
        $last = $collected[\count($collected) - 1];
        $span = ByteSpan::between($first->span->start, $last->span->end());

        return BlockDraft::final(new MdCodeBlock($span, MdCodeBlockStyle::Indented, $content));
    }

    private function stripIndentColumns(Line $line, int $columns): string
    {
        $offset = $this->columnToByteOffset($line, $columns);

        return substr($this->source->bytes, $offset, $line->span->end() - $offset);
    }

    /**
     * Converts a target indentation column into the byte offset where it
     * lands on $line, measured from that physical line's own start (column
     * 0 sits at the line's first byte). Tabs expand to 8-column stops,
     * matching Alto\Rst\Source\Line; a tab that would overshoot the target
     * column is left unconsumed.
     */
    private function columnToByteOffset(Line $line, int $targetColumn): int
    {
        return $this->advanceToColumn($line->span->start, 0, $targetColumn, $line->span->end());
    }

    /**
     * Like columnToByteOffset(), but the walk starts at an arbitrary byte
     * position that is already known to sit at $fromColumn -- used for a
     * list item's marker line, where the whitespace run to skip starts
     * right after the marker text, not at column 0.
     */
    private function advanceToColumn(int $fromPos, int $fromColumn, int $targetColumn, int $end): int
    {
        $bytes = $this->source->bytes;
        $col = $fromColumn;
        $pos = $fromPos;

        while ($pos < $end && $col < $targetColumn) {
            $byte = $bytes[$pos];

            if (' ' === $byte) {
                ++$col;
                ++$pos;

                continue;
            }

            if ("\t" === $byte) {
                $next = $col + (8 - ($col % 8));

                if ($next > $targetColumn) {
                    break;
                }

                $col = $next;
                ++$pos;

                continue;
            }

            break;
        }

        return $pos;
    }

    // -- Block quote -------------------------------------------------------

    private function startsBlockQuote(Line $line): bool
    {
        if ($line->indentWidth > 3) {
            return false;
        }

        $content = $this->content($line);

        return '' !== $content && '>' === $content[0];
    }

    private function stripBlockquoteMarker(Line $line): Line
    {
        $bytes = $this->source->bytes;
        $pos = $line->contentSpan()->start + 1;
        $end = $line->span->end();

        if ($pos < $end && (' ' === $bytes[$pos] || "\t" === $bytes[$pos])) {
            ++$pos;
        }

        return Line::scan($bytes, $line->index, $pos, $end, $line->terminator);
    }

    private function parseBlockQuote(MdLineCursor $cursor): BlockDraft
    {
        $first = $cursor->peek() ?? throw new \LogicException('parseBlockQuote requires a current line.');
        $strippedLines = [];
        $lastPhysical = $first;
        $openParagraph = false;

        while (null !== ($line = $cursor->peek())) {
            if ($this->startsBlockQuote($line)) {
                $stripped = $this->stripBlockquoteMarker($line);
                $strippedLines[] = $stripped;
                $lastPhysical = $line;
                $openParagraph = !$stripped->isBlank() && !$this->looksLikeBlockStart($stripped);
                $cursor->advance();

                continue;
            }

            if ($line->isBlank()) {
                break;
            }

            if ($openParagraph && !$this->looksLikeBlockStart($line)) {
                $strippedLines[] = $line;
                $lastPhysical = $line;
                $cursor->advance();

                continue;
            }

            break;
        }

        $innerCursor = new MdLineCursor($strippedLines);
        $children = $this->parseBlocks($innerCursor);
        $span = ByteSpan::between($first->span->start, $lastPhysical->span->end());

        return BlockDraft::blockQuote($span, $children);
    }

    // -- Lists ---------------------------------------------------------------

    private function bulletMarker(Line $line): ?string
    {
        if ($line->indentWidth > 3) {
            return null;
        }

        $content = $this->content($line);

        if ('' === $content) {
            return null;
        }

        $char = $content[0];

        if (!\in_array($char, ['-', '*', '+'], true)) {
            return null;
        }

        $rest = substr($content, 1);

        if ('' === $rest || ' ' === $rest[0] || "\t" === $rest[0]) {
            return $char;
        }

        return null;
    }

    /**
     * @return array{marker: string, delimiter: MdListDelimiter, start: int}|null
     */
    private function orderedMarker(Line $line): ?array
    {
        if ($line->indentWidth > 3) {
            return null;
        }

        $content = $this->content($line);

        if (1 !== preg_match('/^(\d{1,9})([.)])(?:[ \t]|$)/', $content, $m)) {
            return null;
        }

        return [
            'marker' => $m[1] . $m[2],
            'delimiter' => '.' === $m[2] ? MdListDelimiter::Period : MdListDelimiter::Paren,
            'start' => (int) $m[1],
        ];
    }

    private function matchesListMarker(Line $line, bool $ordered, ?string $bulletMarker, ?MdListDelimiter $delimiter): bool
    {
        if ($ordered) {
            $marker = $this->orderedMarker($line);

            return null !== $marker && $marker['delimiter'] === $delimiter;
        }

        return $this->bulletMarker($line) === $bulletMarker;
    }

    private function parseList(MdLineCursor $cursor, bool $ordered, ?string $bulletMarker, ?MdListDelimiter $delimiter, int $start): BlockDraft
    {
        $first = $cursor->peek() ?? throw new \LogicException('parseList requires a current line.');
        $items = [];
        $loose = false;
        $spanStart = $first->span->start;
        $spanEnd = $first->span->end();

        while (true) {
            $line = $cursor->peek();

            if (null === $line) {
                break;
            }

            if ($line->isBlank()) {
                $savedPos = $cursor->position();
                $cursor->skipBlankLines();
                $next = $cursor->peek();

                if (null === $next || !$this->matchesListMarker($next, $ordered, $bulletMarker, $delimiter)) {
                    $cursor->seek($savedPos);

                    break;
                }

                $loose = true;

                continue;
            }

            if (!$this->matchesListMarker($line, $ordered, $bulletMarker, $delimiter)) {
                break;
            }

            [$itemDraft, $itemLoose, $lastItemLine] = $this->parseListItem($cursor, $ordered);
            $items[] = $itemDraft;
            $loose = $loose || $itemLoose;
            $spanEnd = max($spanEnd, $lastItemLine->span->end());
        }

        $span = ByteSpan::between($spanStart, $spanEnd);

        return BlockDraft::list($span, $ordered, $bulletMarker, $delimiter, $start, !$loose, $items);
    }

    /**
     * @return array{0: BlockDraft, 1: bool, 2: Line}
     */
    private function parseListItem(MdLineCursor $cursor, bool $ordered): array
    {
        $markerLine = $cursor->peek() ?? throw new \LogicException('parseListItem requires a current line.');
        $bytes = $this->source->bytes;
        $contentStart = $markerLine->contentSpan()->start;
        $end = $markerLine->span->end();

        $markerLength = $ordered
            ? \strlen(($this->orderedMarker($markerLine) ?? throw new \LogicException('Expected an ordered marker.'))['marker'])
            : 1;

        $afterMarker = $contentStart + $markerLength;
        $markerColumn = $markerLine->indentWidth + $markerLength;

        $afterMarkerVirtual = Line::scan($bytes, $markerLine->index, $afterMarker, $end, $markerLine->terminator);
        $spacesWidth = $afterMarkerVirtual->indentWidth;
        $hasContentOnMarkerLine = !$afterMarkerVirtual->isBlank();

        $contentColumn = ($hasContentOnMarkerLine && $spacesWidth <= 4)
            ? $markerColumn + $spacesWidth
            : $markerColumn + 1;

        $firstContentLine = $hasContentOnMarkerLine
            ? Line::scan($bytes, $markerLine->index, $this->advanceToColumn($afterMarker, $markerColumn, $contentColumn, $end), $end, $markerLine->terminator)
            : Line::scan($bytes, $markerLine->index, $end, $end, $markerLine->terminator);

        $cursor->advance();

        $collected = [$firstContentLine];

        while (null !== ($line = $cursor->peek())) {
            if ($line->isBlank()) {
                $collected[] = Line::scan($bytes, $line->index, $line->span->end(), $line->span->end(), $line->terminator);
                $cursor->advance();

                continue;
            }

            if ($line->indentWidth >= $contentColumn) {
                $collected[] = Line::scan($bytes, $line->index, $this->columnToByteOffset($line, $contentColumn), $line->span->end(), $line->terminator);
                $cursor->advance();

                continue;
            }

            break;
        }

        $trailingBlanks = 0;

        while (\count($collected) > 1 && $collected[\count($collected) - 1]->isBlank()) {
            array_pop($collected);
            ++$trailingBlanks;
        }

        if ($trailingBlanks > 0) {
            $cursor->seek($cursor->position() - $trailingBlanks);
        }

        $loose = $hasContentOnMarkerLine
            ? $this->containsInternalBlank($collected)
            : $this->containsInternalBlank(\array_slice($collected, 1));

        $lastLine = $collected[\count($collected) - 1];
        $innerCursor = new MdLineCursor($collected);
        $children = $this->parseBlocks($innerCursor);
        $itemSpan = ByteSpan::between($markerLine->span->start, $lastLine->span->end());

        return [BlockDraft::listItem($itemSpan, $children), $loose, $lastLine];
    }

    /**
     * @param list<Line> $lines
     */
    private function containsInternalBlank(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($line->isBlank()) {
                return true;
            }
        }

        return false;
    }

    // -- HTML blocks -----------------------------------------------------

    private function looksLikeHtmlBlockStart(Line $line): bool
    {
        if ($line->indentWidth > 3) {
            return false;
        }

        $content = $this->content($line);

        if ('' === $content || '<' !== $content[0]) {
            return false;
        }

        if (1 === preg_match('/^<!--/', $content)) {
            return true;
        }

        if (1 === preg_match('/^<\?/', $content)) {
            return true;
        }

        if (1 === preg_match('/^<![A-Za-z]/', $content)) {
            return true;
        }

        if (1 === preg_match('/^<!\[CDATA\[/', $content)) {
            return true;
        }

        if (1 !== preg_match('/^<\/?([A-Za-z][A-Za-z0-9-]*)(?:[ \t\/>]|$)/', $content, $m)) {
            return false;
        }

        if (\in_array(strtolower($m[1]), self::HTML_BLOCK_TAGS, true)) {
            return true;
        }

        // Any other tag name only starts a block when it is alone on the
        // line (CommonMark's "type 7"); mixed with trailing text, such as
        // "<span>text</span>", it stays inline content of a paragraph.
        return 1 === preg_match(
            '/^<\/?[A-Za-z][A-Za-z0-9-]*(?:\s+[A-Za-z_:][A-Za-z0-9_.:-]*(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*\s*\/?>[ \t]*$/',
            $content,
        );
    }

    private function parseHtmlBlock(MdLineCursor $cursor): BlockDraft
    {
        $first = $cursor->peek() ?? throw new \LogicException('parseHtmlBlock requires a current line.');
        $last = $first;
        $cursor->advance();

        while (null !== ($line = $cursor->peek()) && !$line->isBlank()) {
            $last = $line;
            $cursor->advance();
        }

        $span = ByteSpan::between($first->span->start, $last->span->end());
        $content = $this->source->slice($span);

        return BlockDraft::final(new MdHtmlBlock($span, $content));
    }

    // -- Link reference definitions, tables, and paragraphs ---------------

    private function parseTextualBlock(MdLineCursor $cursor): ?BlockDraft
    {
        while (null !== ($definition = $this->tryParseDefinition($cursor))) {
            $this->definitions[] = $definition;
        }

        $line = $cursor->peek();

        if (null === $line || $line->isBlank()) {
            return null;
        }

        $table = $this->tryParseTable($cursor);

        if (null !== $table) {
            return $table;
        }

        return $this->parseParagraphOrSetext($cursor);
    }

    private function tryParseDefinition(MdLineCursor $cursor): ?MdLinkReferenceDefinition
    {
        $firstLine = $cursor->peek();

        if (null === $firstLine || $firstLine->indentWidth > 3) {
            return null;
        }

        $content = $this->content($firstLine);

        if (1 !== preg_match('/^\[((?:\\\\.|[^\\\\\]])+)\]:[ \t]*(.*)$/', $content, $m)) {
            return null;
        }

        $label = $m[1];
        $rest = $m[2];
        $saved = $cursor->position();
        $cursor->advance();
        $lastLine = $firstLine;

        if ('' === trim($rest)) {
            $next = $cursor->peek();

            if (null === $next || $next->isBlank() || $next->indentWidth > 3) {
                $cursor->seek($saved);

                return null;
            }

            $rest = $this->content($next);
            $lastLine = $next;
            $cursor->advance();
        }

        $parsedUrl = $this->parseDefinitionUrl(trim($rest));

        if (null === $parsedUrl) {
            $cursor->seek($saved);

            return null;
        }

        [$url, $afterUrl] = $parsedUrl;
        $title = null;
        $trimmedAfter = trim($afterUrl);

        if ('' !== $trimmedAfter) {
            $title = $this->parseDefinitionTitle($trimmedAfter);

            if (null === $title) {
                $cursor->seek($saved);

                return null;
            }
        } else {
            $peek = $cursor->peek();

            if (null !== $peek && !$peek->isBlank() && $peek->indentWidth <= 3) {
                $maybeTitle = $this->parseDefinitionTitle(trim($this->content($peek)));

                if (null !== $maybeTitle) {
                    $title = $maybeTitle;
                    $lastLine = $peek;
                    $cursor->advance();
                }
            }
        }

        $unescapedLabel = MarkdownInlineParser::resolveBackslashEscapes($label);
        $normalized = MarkdownInlineParser::normalizeLabel($unescapedLabel);

        if ('' === $normalized) {
            $cursor->seek($saved);

            return null;
        }

        $span = ByteSpan::between($firstLine->span->start, $lastLine->span->end());

        return new MdLinkReferenceDefinition($span, $unescapedLabel, $normalized, $url, $title);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseDefinitionUrl(string $rest): ?array
    {
        if ('' === $rest) {
            return null;
        }

        if ('<' === $rest[0]) {
            $end = strpos($rest, '>');

            if (false === $end) {
                return null;
            }

            $url = substr($rest, 1, $end - 1);
            $remainder = substr($rest, $end + 1);

            return [MarkdownInlineParser::resolveBackslashEscapes($url), $remainder];
        }

        if (1 === preg_match('/^(\S+)(.*)$/s', $rest, $m)) {
            return [MarkdownInlineParser::resolveBackslashEscapes($m[1]), $m[2]];
        }

        return null;
    }

    private function parseDefinitionTitle(string $text): ?string
    {
        if ('' === $text || \strlen($text) < 2) {
            return null;
        }

        $first = $text[0];
        $last = $text[\strlen($text) - 1];
        $matchingClose = match ($first) {
            '"' => '"',
            "'" => "'",
            '(' => ')',
            default => null,
        };

        if (null === $matchingClose || $last !== $matchingClose) {
            return null;
        }

        return MarkdownInlineParser::resolveBackslashEscapes(substr($text, 1, -1));
    }

    // -- GFM tables --------------------------------------------------------

    private function tryParseTable(MdLineCursor $cursor): ?BlockDraft
    {
        $header = $cursor->peek();
        $delimiterLine = $cursor->peek(1);

        if (null === $header || null === $delimiterLine || $delimiterLine->isBlank() || $header->indentWidth > 3) {
            return null;
        }

        $delimiterContent = trim($this->content($delimiterLine));

        if (!$this->looksLikeTableDelimiterRow($delimiterContent)) {
            return null;
        }

        $headerCells = $this->splitTableRow($header);

        if ([] === $headerCells) {
            return null;
        }

        $alignments = $this->parseTableAlignments($delimiterContent);
        $columnCount = \count($headerCells);

        while (\count($alignments) < $columnCount) {
            $alignments[] = MdTableAlignment::None;
        }

        $alignments = \array_slice($alignments, 0, $columnCount);

        $cursor->advance();
        $cursor->advance();

        $rows = [];
        $lastLine = $delimiterLine;

        while (null !== ($line = $cursor->peek()) && !$line->isBlank()) {
            $rows[] = $this->padOrTrimRow($this->splitTableRow($line), $columnCount);
            $lastLine = $line;
            $cursor->advance();
        }

        $span = ByteSpan::between($header->span->start, $lastLine->span->end());

        return BlockDraft::table($span, $alignments, $headerCells, $rows);
    }

    private function looksLikeTableDelimiterRow(string $content): bool
    {
        // A row without any pipe is indistinguishable from a setext
        // heading underline or a thematic break; GFM tables always need at
        // least one pipe to separate columns.
        if (!str_contains($content, '|')) {
            return false;
        }

        $trimmed = trim($content, '|');

        if ('' === trim($trimmed)) {
            return false;
        }

        $cells = array_map('trim', $this->splitUnescapedPipes($trimmed));

        if ([] === $cells) {
            return false;
        }

        foreach ($cells as $cell) {
            if (1 !== preg_match('/^:?-+:?$/', $cell)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function splitUnescapedPipes(string $content): array
    {
        $cells = [];
        $current = '';
        $length = \strlen($content);

        for ($i = 0; $i < $length; ++$i) {
            $char = $content[$i];

            if ('\\' === $char && $i + 1 < $length) {
                $current .= $char . $content[$i + 1];
                ++$i;

                continue;
            }

            if ('|' === $char) {
                $cells[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $cells[] = $current;

        return $cells;
    }

    /**
     * @return list<array{0: ByteSpan, 1: string}>
     */
    private function splitTableRow(Line $line): array
    {
        $content = $this->content($line);
        $start = $line->contentSpan()->start;
        $leadingTrim = \strlen($content) - \strlen(ltrim($content));
        $offset = $start + $leadingTrim;

        $inner = trim($content);

        if (str_starts_with($inner, '|')) {
            $inner = substr($inner, 1);
            ++$offset;
        }

        $parts = $this->splitUnescapedPipes($inner);

        if (\count($parts) > 1 && '' === rtrim((string) end($parts))) {
            array_pop($parts);
        }

        $cells = [];
        $pos = $offset;

        foreach ($parts as $part) {
            $partLength = \strlen($part);
            [$text, $textStart] = $this->trimWithOffset($part, $pos);
            $cells[] = [ByteSpan::of($textStart, \strlen($text)), $text];
            $pos += $partLength + 1;
        }

        return $cells;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function trimWithOffset(string $text, int $start): array
    {
        $trimmed = ltrim($text);
        $leadingRemoved = \strlen($text) - \strlen($trimmed);
        $trimmed = rtrim($trimmed);

        return [$trimmed, $start + $leadingRemoved];
    }

    /**
     * @return list<MdTableAlignment>
     */
    private function parseTableAlignments(string $delimiterContent): array
    {
        $trimmed = trim($delimiterContent, '|');
        $cells = array_map('trim', $this->splitUnescapedPipes($trimmed));

        return array_map(static function (string $cell): MdTableAlignment {
            $left = str_starts_with($cell, ':');
            $right = str_ends_with($cell, ':');

            return match (true) {
                $left && $right => MdTableAlignment::Center,
                $left => MdTableAlignment::Left,
                $right => MdTableAlignment::Right,
                default => MdTableAlignment::None,
            };
        }, $cells);
    }

    /**
     * @param list<array{0: ByteSpan, 1: string}> $cells
     *
     * @return list<array{0: ByteSpan, 1: string}>
     */
    private function padOrTrimRow(array $cells, int $columnCount): array
    {
        if (\count($cells) > $columnCount) {
            return \array_slice($cells, 0, $columnCount);
        }

        while (\count($cells) < $columnCount) {
            $lastEnd = [] !== $cells ? $cells[\count($cells) - 1][0]->end() : 0;
            $cells[] = [ByteSpan::of($lastEnd, 0), ''];
        }

        return $cells;
    }

    // -- Paragraphs and setext headings -----------------------------------

    private function parseParagraphOrSetext(MdLineCursor $cursor): BlockDraft
    {
        $first = $cursor->peek() ?? throw new \LogicException('parseParagraphOrSetext requires a current line.');
        $cursor->advance();
        $lastLine = $first;

        while (null !== ($line = $cursor->peek())) {
            if ($line->isBlank()) {
                break;
            }

            $setextLevel = $this->setextLevel($line);

            if (null !== $setextLevel) {
                $span = ByteSpan::between($first->span->start, $line->span->end());
                $textSpan = ByteSpan::between($first->contentSpan()->start, $lastLine->span->end());
                $rawText = $this->source->slice($textSpan);
                $cursor->advance();

                return BlockDraft::heading($span, $setextLevel, MdHeadingStyle::Setext, $rawText, $textSpan->start);
            }

            if ($this->looksLikeBlockStart($line)) {
                break;
            }

            $lastLine = $line;
            $cursor->advance();
        }

        $textSpan = ByteSpan::between($first->contentSpan()->start, $lastLine->span->end());
        $rawText = $this->source->slice($textSpan);
        $span = ByteSpan::between($first->span->start, $lastLine->span->end());

        return BlockDraft::paragraph($span, $rawText, $textSpan->start);
    }
}
