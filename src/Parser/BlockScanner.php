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

namespace Alto\Rst\Parser;

use Alto\Rst\Node\BlockQuote;
use Alto\Rst\Node\BulletList;
use Alto\Rst\Node\CitationDefinition;
use Alto\Rst\Node\Comment;
use Alto\Rst\Node\DefinitionList;
use Alto\Rst\Node\DefinitionListItem;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\DirectiveBodyKind;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\EnumeratedList;
use Alto\Rst\Node\EnumerationStyle;
use Alto\Rst\Node\FootnoteDefinition;
use Alto\Rst\Node\HyperlinkTarget;
use Alto\Rst\Node\ListItem;
use Alto\Rst\Node\LiteralBlock;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Section;
use Alto\Rst\Node\SubstitutionDefinition;
use Alto\Rst\Node\Table;
use Alto\Rst\Node\TableCell;
use Alto\Rst\Node\TableRow;
use Alto\Rst\Node\TableStyle;
use Alto\Rst\Node\Text;
use Alto\Rst\Node\Title;
use Alto\Rst\Node\Transition;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Line;
use Alto\Rst\Source\Source;

/**
 * The recursive block scanner behind BlockParser. One instance parses one
 * document; nested regions parse through child cursors over re-based lines.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class BlockScanner
{
    private const string ADORNMENT_CHARS = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    private const array BULLET_MARKERS = ['-', '+', '*', "\u{2022}", "\u{2023}", "\u{2043}"];

    private const string DIRECTIVE_PATTERN = '/^([A-Za-z0-9]+(?:[-._+:][A-Za-z0-9]+)*)::(?:[ \t]+(.*))?$/';

    private const string FIELD_PATTERN = '/^:((?:\\\\.|[^\\\\:])+):(?:[ \t]+(.*))?$/';

    private const string REFERENCE_DEFINITION_PATTERN = '/^\[([^\]]*)\](?:[ \t]+.*)?$/';

    private const string REFERENCE_LABEL_PATTERN = '/^(?:[0-9]+|#(?:[0-9A-Za-z\x80-\xff]+(?:[-._+:][0-9A-Za-z\x80-\xff]+)*)?|\*|[0-9A-Za-z\x80-\xff]+(?:[-._+:][0-9A-Za-z\x80-\xff]+)*)$/';

    private const string SUBSTITUTION_DEFINITION_PATTERN = '/^\|([^|]+)\|[ \t]+(.+)$/';

    /**
     * The first line of a simple table: two or more "=" runs. A single run
     * is a section adornment or a transition, never a table.
     */
    private const string TABLE_TOP_PATTERN = '/^=+(?: +=+)+$/';

    /**
     * The looser shape the head/body separator and the bottom border may
     * take once the top border has fixed the column layout.
     */
    private const string TABLE_BORDER_PATTERN = '/^=[ =]*$/';

    /**
     * A column span underline, which closes the row above it and joins the
     * cells it underlines.
     */
    private const string TABLE_SPAN_PATTERN = '/^-[ -]*$/';

    private const string GRID_TABLE_TOP_PATTERN = '/^\+(?:-+\+)+$/';

    private const string GRID_TABLE_BORDER_PATTERN = '/^\+(?:[-=]+\+)+$/';

    /**
     * Adornment style keys ("u:=", "o:-") in order of first appearance;
     * the 1-based index is the section level.
     *
     * @var list<string>
     */
    private array $titleStyles = [];

    private bool $segmentDiscontinuousText = false;

    public function __construct(
        private readonly Source $source,
        private readonly ProblemCollector $problems,
        private readonly Profile $profile,
    ) {
    }

    public function parseDocument(): Document
    {
        $cursor = new LineCursor(array_map(ParserLine::fromLine(...), $this->source->lines()));

        /** @var list<Node> $root */
        $root = [];
        /** @var list<SectionFrame> $stack */
        $stack = [];
        $lastTopNode = null;

        while (true) {
            $cursor->skipBlankLines();
            $line = $cursor->peek();

            if (null === $line) {
                break;
            }

            $frame = 0 === $line->indentWidth && $this->maybeSectionTitle($cursor, $line)
                ? $this->tryParseSectionTitle($cursor, self::acceptedSectionDepth($stack))
                : null;

            if (null !== $frame) {
                while (\count($stack) >= $frame->level) {
                    $this->closeFrame($stack, $root);
                }

                $stack[] = $frame;

                continue;
            }

            foreach ($this->parseBlock($cursor, 0) as $node) {
                if ($node instanceof Transition) {
                    $containerEmpty = [] !== $stack ? [] === $stack[\count($stack) - 1]->children : [] === $root;

                    if ($containerEmpty) {
                        $this->report(
                            ProblemSeverity::Warning,
                            'transition/at-start',
                            'Document or section may not begin with a transition.',
                            $node->span(),
                        );
                    }
                }

                if ([] !== $stack) {
                    $stack[\count($stack) - 1]->append($node);
                } else {
                    $root[] = $node;
                }

                $lastTopNode = $node;
            }
        }

        if ($lastTopNode instanceof Transition) {
            $this->report(
                ProblemSeverity::Warning,
                'transition/at-end',
                'Document may not end with a transition.',
                $lastTopNode->span(),
            );
        }

        while ([] !== $stack) {
            $this->closeFrame($stack, $root);
        }

        return new Document(ByteSpan::of(0, \strlen($this->source->bytes)), $root);
    }

    /**
     * Pops the innermost open section and appends the built node to its
     * parent frame, or to the document root.
     *
     * @param list<SectionFrame> $stack
     * @param list<Node>         $root
     */
    private function closeFrame(array &$stack, array &$root): void
    {
        $frame = array_pop($stack);

        if (null === $frame) {
            return;
        }

        $section = new Section(
            ByteSpan::between($frame->start, $frame->end),
            $frame->level,
            $frame->title,
            $frame->children,
            $frame->adornment,
            $frame->hasOverline,
        );

        if ([] !== $stack) {
            $stack[\count($stack) - 1]->append($section);
        } else {
            $root[] = $section;
        }
    }

    /**
     * A cheap pre-check before the full section title attempt: a title
     * requires the line itself or the line below it to start with an
     * adornment character. Neither rejected shape reports a problem in
     * tryParseSectionTitle, so skipping the full attempt changes nothing.
     */
    private function maybeSectionTitle(LineCursor $cursor, ParserLine $line): bool
    {
        $bytes = $this->source->bytes;

        if ($line->contentStart < $line->end() && str_contains(self::ADORNMENT_CHARS, $bytes[$line->contentStart])) {
            return true;
        }

        $next = $cursor->peek(1);

        return null !== $next
            && !$next->blank
            && 0 === $next->indentWidth
            && str_contains(self::ADORNMENT_CHARS, $bytes[$next->contentStart]);
    }

    private function tryParseSectionTitle(LineCursor $cursor, int $depth): ?SectionFrame
    {
        $line0 = $cursor->peek();

        if (null === $line0) {
            return null;
        }

        $content0 = rtrim($this->content($line0));

        if ($this->startsNonTextConstruct($cursor, $content0)) {
            return null;
        }

        if (self::isAdornment($content0)) {
            return $this->tryParseOverlineTitle($cursor, $depth, $line0, $content0);
        }

        $line1 = $cursor->peek(1);

        if (null === $line1 || $line1->blank || 0 !== $line1->indentWidth) {
            return null;
        }

        $underline = rtrim($this->content($line1));

        if (!self::isAdornment($underline)) {
            return null;
        }

        $titleLength = self::charLength(trim($content0));
        $underlineLength = \strlen($underline);

        if ($underlineLength < $titleLength && $underlineLength < 4) {
            $this->report(
                ProblemSeverity::Info,
                'section/possible-underline',
                'Possible section title underline is too short for the title; treating it as ordinary text.',
                $this->spanOf($line0, $line1),
            );

            return null;
        }

        if ($underlineLength < $titleLength) {
            $this->report(
                ProblemSeverity::Warning,
                'section/short-adornment',
                'Section title underline is shorter than the title.',
                $this->spanOf($line0, $line1),
            );
        }

        $cursor->advance();
        $cursor->advance();

        $resolution = $this->resolveSectionLevel('u:'.$underline[0], $depth, $this->spanOf($line0, $line1));

        return new SectionFrame(
            $resolution['level'],
            $this->makeTitle($line0),
            $underline[0],
            false,
            $line0->spanStart,
            $line1->end(),
            $resolution['accepted'],
        );
    }

    private function tryParseOverlineTitle(
        LineCursor $cursor,
        int $depth,
        ParserLine $line0,
        string $overline,
    ): ?SectionFrame {
        $line1 = $cursor->peek(1);

        if (null === $line1 || $line1->blank) {
            return null;
        }

        $line2 = $cursor->peek(2);
        $underline = null !== $line2 ? rtrim($this->content($line2)) : '';

        if (null === $line2 || !self::isAdornment($underline)) {
            return null;
        }

        $adornment = $overline[0];

        if ($underline !== $overline) {
            return null;
        }

        $titleLength = self::charLength(trim($this->content($line1)));

        if (min(\strlen($overline), \strlen($underline)) < $titleLength) {
            $this->report(
                ProblemSeverity::Warning,
                'section/short-adornment',
                'Section title adornment is shorter than the title.',
                $this->spanOf($line0, $line2),
            );
        }

        $cursor->advance();
        $cursor->advance();
        $cursor->advance();

        $resolution = $this->resolveSectionLevel('o:'.$adornment, $depth, $this->spanOf($line0, $line2));

        return new SectionFrame(
            $resolution['level'],
            $this->makeTitle($line1),
            $adornment,
            true,
            $line0->spanStart,
            $line2->end(),
            $resolution['accepted'],
        );
    }

    private function startsNonTextConstruct(LineCursor $cursor, string $content): bool
    {
        if ('..' === $content || str_starts_with($content, '.. ')) {
            return true;
        }

        if ('__' === $content || str_starts_with($content, '__ ')) {
            return true;
        }

        if (null !== self::bulletMarker($content)) {
            return true;
        }

        return null !== self::enumerator($content) && $this->isEnumeratedListStart($cursor);
    }

    /**
     * @return array{level: int, accepted: bool}
     */
    private function resolveSectionLevel(string $styleKey, int $depth, ByteSpan $span): array
    {
        $index = array_search($styleKey, $this->titleStyles, true);

        if (false !== $index) {
            $level = $index + 1;

            if ($level > $depth + 1) {
                $this->report(
                    ProblemSeverity::Error,
                    'section/inconsistent-style',
                    \sprintf('Section adornment style implies level %d but only level %d is open here.', $level, $depth + 1),
                    $span,
                );

                return ['level' => $depth + 1, 'accepted' => false];
            }

            return ['level' => $level, 'accepted' => true];
        }

        $level = \count($this->titleStyles) + 1;

        if ($level > $depth + 1) {
            $this->report(
                ProblemSeverity::Error,
                'section/inconsistent-style',
                \sprintf('New section adornment style would create level %d but only level %d is open here.', $level, $depth + 1),
                $span,
            );

            return ['level' => $depth + 1, 'accepted' => false];
        }

        $this->titleStyles[] = $styleKey;

        return ['level' => $level, 'accepted' => true];
    }

    /**
     * Recovery sections preserve rejected title content, but they cannot
     * establish the depth at which a later adornment style is registered.
     *
     * @param list<SectionFrame> $stack
     */
    private static function acceptedSectionDepth(array $stack): int
    {
        $depth = 0;

        foreach ($stack as $frame) {
            if (!$frame->styleAccepted) {
                break;
            }

            $depth = $frame->level;
        }

        return $depth;
    }

    private function makeTitle(ParserLine $line): Title
    {
        $content = rtrim($this->content($line));
        $textSpan = ByteSpan::between($line->contentStart, $line->contentStart + \strlen($content));

        return new Title(
            ByteSpan::between($line->spanStart, $line->end()),
            new Text($textSpan, $this->source->slice($textSpan)),
        );
    }

    /**
     * @return list<Node>
     */
    private function parseBlocks(LineCursor $cursor, int $indent): array
    {
        $nodes = [];

        while (true) {
            $cursor->skipBlankLines();
            $line = $cursor->peek();

            if (null === $line || $line->indentWidth < $indent) {
                break;
            }

            foreach ($this->parseBlock($cursor, $indent) as $node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * Parses one block starting at the cursor's non-blank line.
     *
     * @return list<Node>
     */
    private function parseBlock(LineCursor $cursor, int $indent): array
    {
        $line = $cursor->peek();

        if (null === $line) {
            return [];
        }

        if ($line->indentWidth > $indent) {
            return [$this->parseBlockQuote($cursor, $indent)];
        }

        $content = rtrim($this->content($line));

        if ('..' === $content || str_starts_with($content, '.. ')) {
            return $this->parseExplicitMarkup($cursor);
        }

        if ('__' === $content || str_starts_with($content, '__ ')) {
            return [$this->parseAnonymousShortTarget($cursor, $line, $content)];
        }

        if (null !== ($marker = self::bulletMarker($content))) {
            return [$this->parseBulletList($cursor, $indent, $marker)];
        }

        if (null !== self::enumerator($content) && $this->isEnumeratedListStart($cursor)) {
            return [$this->parseEnumeratedList($cursor, $indent)];
        }

        if ('+' === ($content[0] ?? '') && 1 === preg_match(self::GRID_TABLE_TOP_PATTERN, $content)) {
            return $this->parseGridTable($cursor, $indent, $line);
        }

        if (str_starts_with($content, '=') && 1 === preg_match(self::TABLE_TOP_PATTERN, $content)) {
            return $this->parseSimpleTable($cursor, $indent, $line);
        }

        if ($this->isDefinitionListStart($cursor, $indent)) {
            return [$this->parseDefinitionList($cursor, $indent)];
        }

        if (self::isAdornment($content)) {
            $recovery = $this->parseMalformedOverline($cursor, $line, $content);

            if (null !== $recovery) {
                return [$recovery];
            }

            $next = $cursor->peek(1);

            if ((null === $next || $next->blank) && \strlen($content) >= 4) {
                $cursor->advance();

                return [new Transition(ByteSpan::between($line->spanStart, $line->end()))];
            }

        // shorter runs and adornments followed by text are ordinary text
        } elseif (null !== ($shape = self::unsupportedShape($content))) {
            $this->report(
                ProblemSeverity::Info,
                'parser/unsupported-construct',
                \sprintf('This looks like a %s, which is not supported yet; parsing it as a paragraph.', $shape),
                $this->spanOf($line, $line),
            );
        }

        return $this->parseTextBlock($cursor, $indent);
    }

    private function parseMalformedOverline(
        LineCursor $cursor,
        ParserLine $overline,
        string $adornment,
    ): ?LiteralBlock {
        if (\strlen($adornment) < 4) {
            return null;
        }

        $title = $cursor->peek(1);

        if (null === $title || $title->blank) {
            return null;
        }

        $underline = $cursor->peek(2);
        $underlineText = null === $underline ? '' : rtrim($this->content($underline));

        if (null !== $underline && self::isAdornment($underlineText) && $underlineText === $adornment) {
            return null;
        }

        if (null !== $underline && self::isAdornment($underlineText)) {
            $last = $underline;
            $code = 'section/overline-underline-mismatch';
            $message = \sprintf(
                'Section title overline "%s" does not match underline "%s"; preserving the malformed title as a literal block.',
                $adornment,
                $underlineText,
            );
            $cursor->advance();
            $cursor->advance();
            $cursor->advance();
        } else {
            $last = $title;
            $code = 'section/missing-underline';
            $message = 'Section title overline has no matching underline; preserving the malformed title as a literal block.';
            $cursor->advance();
            $cursor->advance();
        }

        $span = ByteSpan::between($overline->spanStart, $last->end());
        $this->report(ProblemSeverity::Error, $code, $message, $span);

        return new LiteralBlock($span, $span);
    }

    /**
     * Parses a paragraph and, when it announces one, the following literal
     * block.
     *
     * @return list<Node>
     */
    private function parseTextBlock(LineCursor $cursor, int $indent): array
    {
        $first = $cursor->peek();

        if (null === $first) {
            return [];
        }

        $cursor->advance();
        $lines = [$first];

        while (null !== ($line = $cursor->peek()) && !$line->blank && $line->indentWidth === $indent) {
            $lines[] = $line;
            $cursor->advance();
        }

        $next = $cursor->peek();
        $last = $lines[\count($lines) - 1];
        $lastContent = rtrim($this->content($last));
        $literal = str_ends_with($lastContent, '::');
        $markerLine = null;

        if ($literal && '::' === $lastContent) {
            $markerLine = array_pop($lines);
        }

        $nodes = [];

        if ([] !== $lines) {
            $keptLast = $lines[\count($lines) - 1];
            $keptContent = rtrim($this->content($keptLast));

            if ($literal && null === $markerLine) {
                $stripped = substr($keptContent, 0, -2);
                $keptContent = rtrim($stripped) !== $stripped ? rtrim($stripped) : $stripped.':';
            }

            $textSpan = ByteSpan::between($first->contentStart, $keptLast->contentStart + \strlen($keptContent));
            $text = $this->source->slice($textSpan);
            $sourceSegments = [];

            if ($this->segmentDiscontinuousText && !self::linesAreContiguous($lines)) {
                $segments = [];

                foreach ($lines as $index => $line) {
                    $content = $index === \count($lines) - 1 ? $keptContent : $this->content($line);
                    $segments[] = $content;
                    $sourceSegments[] = ByteSpan::of($line->contentStart, \strlen($content));
                }

                $text = implode("\n", $segments);
            }

            $nodes[] = new Paragraph(
                ByteSpan::between($first->spanStart, $keptLast->end()),
                new Text($textSpan, $text, $sourceSegments),
            );
        }

        if ($literal) {
            $literalBlock = $this->parseLiteralBlock($cursor, $indent, $next, $markerLine ?? $last);

            if (null !== $literalBlock) {
                $nodes[] = $literalBlock;
            }
        } elseif (null !== $next && !$next->blank && $next->indentWidth > $indent) {
            $this->report(
                ProblemSeverity::Error,
                'parser/unexpected-indentation',
                'Unexpected indentation after the paragraph; parsing the indented lines as a block quote.',
                $this->spanOf($next, $next),
            );
        }

        return $nodes;
    }

    /**
     * @param list<ParserLine> $lines
     */
    private static function linesAreContiguous(array $lines): bool
    {
        $previous = null;

        foreach ($lines as $line) {
            if (null !== $previous && $previous->end() + \strlen($previous->line->terminator) !== $line->spanStart) {
                return false;
            }

            $previous = $line;
        }

        return true;
    }

    private function isDefinitionListStart(LineCursor $cursor, int $indent): bool
    {
        $term = $cursor->peek();
        $definition = $cursor->peek(1);

        return null !== $term
            && !$term->blank
            && $term->indentWidth === $indent
            && !str_ends_with(rtrim($this->content($term)), '::')
            && null !== $definition
            && !$definition->blank
            && $definition->indentWidth > $indent;
    }

    private function parseDefinitionList(LineCursor $cursor, int $indent): DefinitionList
    {
        $first = $cursor->peek() ?? throw new \LogicException('Definition list parsing requires a current line.');
        $items = [];
        $end = $first->end();

        while ($this->isDefinitionListStart($cursor, $indent)) {
            $termLine = $cursor->peek() ?? throw new \LogicException('Definition item parsing requires a term.');
            $cursor->advance();
            $run = $this->collectIndented($cursor, $indent);
            $base = \PHP_INT_MAX;

            foreach ($run as $line) {
                if (!$line->blank) {
                    $base = min($base, $line->indentWidth);
                }
            }

            [$term, $classifiers] = $this->definitionTerm($termLine);
            $definition = $this->parseBlocks(new LineCursor($run), $base);
            $last = self::lastNonBlank($run) ?? $termLine;
            $end = $last->end();
            $items[] = new DefinitionListItem(
                ByteSpan::between($termLine->spanStart, $end),
                $term,
                $classifiers,
                $definition,
            );

            $cursor->skipBlankLines();
        }

        return new DefinitionList(ByteSpan::between($first->spanStart, $end), $items);
    }

    /**
     * @return array{Text, list<Text>}
     */
    private function definitionTerm(ParserLine $line): array
    {
        $content = rtrim($this->content($line));
        $parts = [];
        $start = 0;

        if (false !== preg_match_all('/[ \t]+:[ \t]+/', $content, $matches, \PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$delimiter, $offset]) {
                $slashes = 0;

                for ($index = $offset - 1; $index >= 0 && '\\' === $content[$index]; --$index) {
                    ++$slashes;
                }

                if (1 === $slashes % 2) {
                    continue;
                }

                $parts[] = [$start, $offset];
                $start = $offset + \strlen($delimiter);
            }
        }

        $parts[] = [$start, \strlen($content)];
        $texts = [];

        foreach ($parts as [$partStart, $partEnd]) {
            while ($partStart < $partEnd && (' ' === $content[$partStart] || "\t" === $content[$partStart])) {
                ++$partStart;
            }

            while ($partEnd > $partStart && (' ' === $content[$partEnd - 1] || "\t" === $content[$partEnd - 1])) {
                --$partEnd;
            }

            $span = ByteSpan::between($line->contentStart + $partStart, $line->contentStart + $partEnd);
            $texts[] = new Text($span, $this->source->slice($span));
        }

        return [array_shift($texts) ?? new Text(ByteSpan::of($line->contentStart, 0), ''), $texts];
    }

    /**
     * Collects the indented block a "::" paragraph announced. Returns null
     * and reports when no literal content follows.
     */
    private function parseLiteralBlock(LineCursor $cursor, int $indent, ?ParserLine $next, ParserLine $marker): ?LiteralBlock
    {
        $run = [];

        if (null !== $next && !$next->blank && $next->indentWidth > $indent) {
            $this->report(
                ProblemSeverity::Error,
                'literal/missing-blank-line',
                'Blank line missing before the literal block.',
                $this->spanOf($next, $next),
            );

            $run = $this->collectIndented($cursor, $indent);
        } else {
            $saved = $cursor->position();
            $cursor->skipBlankLines();
            $peek = $cursor->peek();

            if (null !== $peek && $peek->indentWidth > $indent) {
                $run = $this->collectIndented($cursor, $indent);
            } elseif (null !== $peek && !$peek->blank && $peek->indentWidth === $indent
                && str_contains(self::ADORNMENT_CHARS, $this->content($peek)[0])
            ) {
                $run = $this->collectQuotedLiteral($cursor, $indent, $this->content($peek)[0]);
            } else {
                $cursor->seek($saved);
            }
        }

        if ([] === $run) {
            $this->report(
                ProblemSeverity::Warning,
                'literal/missing-content',
                'Literal block expected after "::"; none found.',
                $this->spanOf($marker, $marker),
            );

            return null;
        }

        $lastLine = self::lastNonBlank($run) ?? $run[0];
        $content = ByteSpan::between($run[0]->line->span->start, $lastLine->end());
        $start = '::' === rtrim($this->content($marker)) ? $marker->spanStart : $content->start;

        return new LiteralBlock(ByteSpan::between($start, $lastLine->end()), $content);
    }

    /**
     * A quoted literal block: unindented lines all starting with the same
     * punctuation character, per docutils.
     *
     * @return list<ParserLine>
     */
    private function collectQuotedLiteral(LineCursor $cursor, int $indent, string $quote): array
    {
        $run = [];

        while (null !== ($line = $cursor->peek()) && !$line->blank && $line->indentWidth === $indent) {
            if (!str_starts_with($this->content($line), $quote)) {
                $this->report(
                    ProblemSeverity::Error,
                    'literal/inconsistent-quoting',
                    \sprintf('Inconsistent literal block quoting: line does not start with "%s".', $quote),
                    $this->spanOf($line, $line),
                );

                break;
            }

            $run[] = $line;
            $cursor->advance();
        }

        return $run;
    }

    private function parseBlockQuote(LineCursor $cursor, int $contextIndent): BlockQuote
    {
        $run = $this->collectIndented($cursor, $contextIndent);
        $base = \PHP_INT_MAX;

        foreach ($run as $line) {
            if (!$line->blank) {
                $base = min($base, $line->indentWidth);
            }
        }

        $children = $this->parseBlocks(new LineCursor($run), $base);
        $lastLine = self::lastNonBlank($run) ?? $run[0];

        return new BlockQuote(ByteSpan::between($run[0]->spanStart, $lastLine->end()), $children);
    }

    private function parseBulletList(LineCursor $cursor, int $indent, string $marker): BulletList
    {
        $first = $cursor->peek() ?? throw new \LogicException('Bullet list parsing requires a current line.');
        $start = $first->spanStart;
        $end = $first->end();
        $items = [];

        while (true) {
            $cursor->skipBlankLines();
            $line = $cursor->peek();

            if (null === $line || $line->indentWidth !== $indent) {
                $this->warnAdjacentListEnd($cursor, $line, 'Bullet list');

                break;
            }

            $itemMarker = self::bulletMarker(rtrim($this->content($line)));

            if (null === $itemMarker) {
                $this->warnAdjacentListEnd($cursor, $line, 'Bullet list');

                break;
            }

            if ($itemMarker !== $marker) {
                $previous = $cursor->previous();

                if (null !== $previous && !$previous->blank) {
                    $this->report(
                        ProblemSeverity::Warning,
                        'list/mixed-markers',
                        \sprintf('Bullet marker changed from "%s" to "%s" without a separating blank line; starting a new list.', $marker, $itemMarker),
                        $this->spanOf($line, $line),
                    );
                }

                break;
            }

            $item = $this->parseListItem($cursor, \strlen($itemMarker), 1);
            $items[] = $item;
            $end = max($end, $item->span()->end());
        }

        return new BulletList(ByteSpan::between($start, $end), $marker, $items);
    }

    private function parseEnumeratedList(LineCursor $cursor, int $indent): EnumeratedList
    {
        $first = $cursor->peek() ?? throw new \LogicException('Enumerated list parsing requires a current line.');
        $firstEnumerator = self::enumerator(rtrim($this->content($first)))
            ?? throw new \LogicException('Enumerated list parsing requires an enumerator.');

        $format = $firstEnumerator['format'];
        $style = $firstEnumerator['style'] ?? EnumerationStyle::Arabic;
        $startValue = $firstEnumerator['value'] ?? 1;
        $expected = $startValue;
        $start = $first->spanStart;
        $end = $first->end();
        $items = [];

        while (true) {
            $cursor->skipBlankLines();
            $line = $cursor->peek();

            if (null === $line || $line->indentWidth !== $indent) {
                $this->warnAdjacentListEnd($cursor, $line, 'Enumerated list');

                break;
            }

            $enumerator = self::enumerator(rtrim($this->content($line)), $style);

            if (null === $enumerator
                || $enumerator['format'] !== $format
                || (null !== $enumerator['style'] && $enumerator['style'] !== $style)
                || (null !== $enumerator['value'] && $enumerator['value'] !== $expected)
            ) {
                $this->warnAdjacentListEnd($cursor, $line, 'Enumerated list');

                break;
            }

            $item = $this->parseListItem($cursor, \strlen($enumerator['marker']), \strlen($enumerator['marker']));
            $items[] = $item;
            ++$expected;
            $end = max($end, $item->span()->end());
        }

        return new EnumeratedList(ByteSpan::between($start, $end), $style, $startValue, $items);
    }

    /**
     * Docutils requires the line after a potential first enumerated item to
     * be blank, indented, or another enumerator; otherwise the text is an
     * ordinary paragraph.
     */
    private function isEnumeratedListStart(LineCursor $cursor): bool
    {
        $line = $cursor->peek();
        $next = $cursor->peek(1);

        if (null === $line) {
            return false;
        }

        $first = self::enumerator(rtrim($this->content($line)));

        if (null === $first || null === $next || $next->blank) {
            return true;
        }

        if ($next->indentWidth > $line->indentWidth) {
            return true;
        }

        if ($next->indentWidth === $line->indentWidth) {
            $style = $first['style'] ?? EnumerationStyle::Arabic;
            $second = self::enumerator(rtrim($this->content($next)), $style);

            return null !== $second
                && $second['format'] === $first['format']
                && (null === $second['style'] || $second['style'] === $style)
                && (null === $second['value'] || $second['value'] === ($first['value'] ?? 1) + 1);
        }

        return true;
    }

    private function parseListItem(LineCursor $cursor, int $markerBytes, int $markerColumns): ListItem
    {
        $line0 = $cursor->peek() ?? throw new \LogicException('List item parsing requires a current line.');
        $cursor->advance();

        $bytes = $this->source->bytes;
        $lineEnd = $line0->end();
        $column = $line0->indentWidth + $markerColumns;
        $textStart = $line0->contentStart + $markerBytes;

        while ($textStart < $lineEnd) {
            $byte = $bytes[$textStart];

            if (' ' === $byte) {
                ++$column;
            } elseif ("\t" === $byte) {
                $column += 8 - ($column % 8);
            } else {
                break;
            }

            ++$textStart;
        }

        if ($textStart >= $lineEnd) {
            return $this->parseEmptyFirstLineItem($cursor, $line0, $markerBytes);
        }

        $run = [];

        while (null !== ($line = $cursor->peek()) && ($line->blank || $line->indentWidth >= $column)) {
            $run[] = $line;
            $cursor->advance();
        }

        while ([] !== $run && $run[\count($run) - 1]->blank) {
            array_pop($run);
        }

        $virtual = new ParserLine($line0->line, $column, $textStart, $textStart, false);
        $children = $this->parseBlocks(new LineCursor([$virtual, ...$run]), $column);
        $lastLine = self::lastNonBlank($run);
        $end = null !== $lastLine ? $lastLine->end() : $line0->end();

        return new ListItem(ByteSpan::between($line0->spanStart, $end), $children);
    }

    /**
     * A marker alone on its line: the item content is the following block
     * indented past the marker column, blank-separated or not.
     */
    private function parseEmptyFirstLineItem(LineCursor $cursor, ParserLine $line0, int $markerBytes): ListItem
    {
        $saved = $cursor->position();
        $cursor->skipBlankLines();
        $peek = $cursor->peek();

        if (null === $peek || $peek->indentWidth <= $line0->indentWidth) {
            $cursor->seek($saved);

            return new ListItem(ByteSpan::between($line0->spanStart, $line0->contentStart + $markerBytes), []);
        }

        $cursor->seek($saved);
        $run = $this->collectIndented($cursor, $line0->indentWidth);
        $base = \PHP_INT_MAX;

        foreach ($run as $line) {
            if (!$line->blank) {
                $base = min($base, $line->indentWidth);
            }
        }

        $children = $this->parseBlocks(new LineCursor($run), $base);
        $lastLine = self::lastNonBlank($run) ?? $line0;

        return new ListItem(ByteSpan::between($line0->spanStart, $lastLine->end()), $children);
    }

    private function warnAdjacentListEnd(LineCursor $cursor, ?ParserLine $line, string $listKind): void
    {
        $previous = $cursor->previous();

        if (null === $line || $line->blank || null === $previous || $previous->blank) {
            return;
        }

        $this->report(
            ProblemSeverity::Warning,
            'list/missing-blank-line',
            \sprintf('%s ends without a blank line before the next block.', $listKind),
            $this->spanOf($line, $line),
        );
    }

    /**
     * Parses a docutils simple table: a "=" top border, optional head rows
     * closed by a second border, body rows, and a bottom border. The top
     * border fixes the column layout; the last column is unbounded, so its
     * text may overflow the border it was written under.
     *
     * @return list<Node>
     */
    private function parseSimpleTable(LineCursor $cursor, int $indent, ParserLine $top): array
    {
        $topContent = rtrim($this->content($top));
        $block = [];
        $bounds = $this->findTableBounds($cursor, $indent, \strlen($topContent), $block);

        if (null === $bounds) {
            $this->report(
                ProblemSeverity::Error,
                'table/malformed-border',
                'Simple table has no bottom border; parsing the lines as a paragraph.',
                $this->spanOf($top, $top),
            );

            return $this->parseTextBlock($cursor, $indent);
        }

        [$lastIndex, $headSeparator] = $bounds;

        for ($step = 0; $step <= $lastIndex; ++$step) {
            $cursor->advance();
        }

        $columns = self::parseTableColumns($topContent, '=');
        $rows = $this->collectTableRows($block, $indent, $columns, $headSeparator);
        $firstBody = self::firstBodyRow($rows, $headSeparator);

        $nodes = [];

        foreach ($rows as $row) {
            $nodes[] = $row[0];
        }

        $body = \array_slice($nodes, $firstBody);

        if ([] === $body) {
            $this->report(
                ProblemSeverity::Warning,
                'table/no-body',
                'Simple table has no body rows.',
                $this->spanOf($top, $block[$lastIndex]),
            );
        }

        return [new Table(
            ByteSpan::between($top->spanStart, $block[$lastIndex]->end()),
            \array_slice($nodes, 0, $firstBody),
            $body,
            array_map(static fn (array $column): int => $column[1] - $column[0], $columns),
            TableStyle::Simple,
        )];
    }

    /**
     * Parses the rectangular grid-table form used by docutils and Symfony
     * documentation. Cells may contain nested blocks and span columns when
     * an internal vertical rule is omitted.
     *
     * @return list<Node>
     */
    private function parseGridTable(LineCursor $cursor, int $indent, ParserLine $top): array
    {
        $positions = self::gridBoundaryPositions(rtrim($this->content($top)));
        $block = [$top];
        $lastIndex = null;
        $headSeparator = null;

        for ($index = 1; null !== ($line = $cursor->peek($index)); ++$index) {
            if ($line->blank || $line->indentWidth !== $indent) {
                break;
            }

            $content = rtrim($this->content($line));
            $border = 1 === preg_match(self::GRID_TABLE_BORDER_PATTERN, $content)
                && self::gridBoundaryPositions($content) === $positions;

            if (!$border && !$this->isGridContentLine($line, $indent, $positions)) {
                break;
            }

            $block[] = $line;

            if (!$border) {
                continue;
            }

            if (str_contains($content, '=')) {
                $headSeparator ??= $index;
            }

            $next = $cursor->peek($index + 1);

            if (null === $next || !$this->isGridContentLine($next, $indent, $positions)) {
                $lastIndex = $index;

                break;
            }
        }

        if (null === $lastIndex) {
            $this->report(
                ProblemSeverity::Error,
                'table/malformed-border',
                'Grid table has no matching bottom border; parsing the lines as a paragraph.',
                $this->spanOf($top, $top),
            );

            return $this->parseTextBlock($cursor, $indent);
        }

        for ($step = 0; $step <= $lastIndex; ++$step) {
            $cursor->advance();
        }

        $rows = [];
        $start = 1;

        for ($offset = 1; $offset <= $lastIndex; ++$offset) {
            $content = rtrim($this->content($block[$offset]));

            if (1 !== preg_match(self::GRID_TABLE_BORDER_PATTERN, $content)) {
                continue;
            }

            $lines = \array_slice($block, $start, $offset - $start);

            if ([] !== $lines) {
                $rows[] = [$this->buildGridTableRow($lines, $indent, $positions), $start];
            }

            $start = $offset + 1;
        }

        $firstBody = self::firstBodyRow($rows, $headSeparator);
        $nodes = array_map(static fn (array $row): TableRow => $row[0], $rows);
        $body = \array_slice($nodes, $firstBody);

        if ([] === $body) {
            $this->report(
                ProblemSeverity::Warning,
                'table/no-body',
                'Grid table has no body rows.',
                $this->spanOf($top, $block[$lastIndex]),
            );
        }

        $widths = [];

        for ($index = 1, $count = \count($positions); $index < $count; ++$index) {
            $widths[] = $positions[$index] - $positions[$index - 1] - 1;
        }

        return [new Table(
            ByteSpan::between($top->spanStart, $block[$lastIndex]->end()),
            \array_slice($nodes, 0, $firstBody),
            $body,
            $widths,
            TableStyle::Grid,
        )];
    }

    /**
     * @param list<ParserLine> $lines
     * @param list<int>        $positions
     */
    private function buildGridTableRow(array $lines, int $indent, array $positions): TableRow
    {
        $boundaries = $this->gridContentBoundaries($lines[0], $indent, $positions);

        foreach (\array_slice($lines, 1) as $line) {
            if ($this->gridContentBoundaries($line, $indent, $positions) === $boundaries) {
                continue;
            }

            $this->report(
                ProblemSeverity::Warning,
                'table/column-mismatch',
                'Grid table cell boundaries change inside a row; using the first content line.',
                $this->spanOf($line, $line),
            );
        }

        $cells = [];

        for ($index = 1, $count = \count($boundaries); $index < $count; ++$index) {
            $left = $boundaries[$index - 1];
            $right = $boundaries[$index];
            $cells[] = $this->buildTableCell(
                $lines,
                $indent,
                $positions[$left] + 1,
                $positions[$right],
                $right - $left,
                true,
            );
        }

        $last = self::lastNonBlank($lines) ?? $lines[0];

        return new TableRow(ByteSpan::between($lines[0]->spanStart, $last->end()), $cells);
    }

    /**
     * @param list<int> $positions
     *
     * @return list<int>
     */
    private function gridContentBoundaries(ParserLine $line, int $indent, array $positions): array
    {
        $boundaries = [];

        foreach ($positions as $index => $position) {
            if ('|' === $this->tableSlice($line, $indent, $position, $position + 1)) {
                $boundaries[] = $index;
            }
        }

        return $boundaries;
    }

    /**
     * @param list<int> $positions
     */
    private function isGridContentLine(ParserLine $line, int $indent, array $positions): bool
    {
        if ($line->blank || $line->indentWidth !== $indent) {
            return false;
        }

        $boundaries = $this->gridContentBoundaries($line, $indent, $positions);

        return 2 <= \count($boundaries)
            && 0 === $boundaries[0]
            && \count($positions) - 1 === $boundaries[\count($boundaries) - 1];
    }

    /**
     * @return list<int>
     */
    private static function gridBoundaryPositions(string $border): array
    {
        $positions = [];

        for ($offset = 0; false !== ($position = strpos($border, '+', $offset)); $offset = $position + 1) {
            $positions[] = $position;
        }

        return $positions;
    }

    /**
     * Locates the bottom border and, when the table has head rows, the
     * head/body separator, scanning the window lazily: the lines a table
     * may claim run up to the first line that leaves the enclosing block,
     * blank lines included, because a simple table may separate its rows
     * with them. The first border ends the table unless a non-blank window
     * line follows it, in which case it separates head from body and the
     * next border ends the table.
     *
     * The scan stops as soon as the bounds are known instead of collecting
     * the whole window first: a top-border lookalike that never closes used
     * to pull every following line into an array, which made a document of
     * such lookalikes quadratic. On success $block holds the table lines,
     * indices 0 (the top border) through the returned bottom border index.
     *
     * @param list<ParserLine> $block
     *
     * @return array{int, int|null}|null
     */
    private function findTableBounds(LineCursor $cursor, int $indent, int $topLength, array &$block): ?array
    {
        $top = $cursor->peek();

        if (null === $top) {
            return null;
        }

        $block = [$top];
        $bytes = $this->source->bytes;
        $first = null;

        for ($index = 1; null !== ($line = $cursor->peek($index)) && ($line->blank || $line->indentWidth >= $indent); ++$index) {
            $block[] = $line;

            if ($line->blank || $line->indentWidth !== $indent || '=' !== $bytes[$line->contentStart]) {
                continue;
            }

            $content = rtrim($this->content($line));

            if (1 !== preg_match(self::TABLE_BORDER_PATTERN, $content)) {
                continue;
            }

            if (\strlen($content) !== $topLength) {
                $this->report(
                    ProblemSeverity::Warning,
                    'table/malformed-border',
                    'Simple table border does not match the top border; keeping the top border column layout.',
                    $this->spanOf($line, $line),
                );
            }

            if (null !== $first) {
                return [$index, $first];
            }

            $next = $cursor->peek($index + 1);

            if (null === $next || (!$next->blank && $next->indentWidth < $indent) || $next->blank) {
                return [$index, null];
            }

            $first = $index;
        }

        return null;
    }

    /**
     * Splits the table block into rows. A border, a column span underline,
     * and a non-blank first column each start a row; a line whose first
     * column is blank continues the row above it.
     *
     * @param list<ParserLine>      $block
     * @param list<array{int, int}> $columns
     *
     * @return list<array{TableRow, int}>
     */
    private function collectTableRows(array $block, int $indent, array $columns, ?int $headSeparator): array
    {
        $rows = [];
        $count = \count($block);
        $firstEnd = $columns[0][1];
        $start = 1;
        $textFound = false;

        for ($offset = 1; $offset < $count; ++$offset) {
            $line = $block[$offset];
            $terminator = $this->tableTerminatorColumns($line, $indent, $offset === $count - 1 || $offset === $headSeparator);

            if (null !== $terminator) {
                $row = $this->buildTableRow(\array_slice($block, $start, $offset - $start), $indent, $columns, $terminator);

                if (null !== $row) {
                    $rows[] = [$row, $start];
                }

                $start = $offset + 1;
                $textFound = false;

                continue;
            }

            if ('' !== trim($this->tableSlice($line, $indent, 0, $firstEnd))) {
                if ($textFound && $offset !== $start) {
                    $row = $this->buildTableRow(\array_slice($block, $start, $offset - $start), $indent, $columns, $columns);

                    if (null !== $row) {
                        $rows[] = [$row, $start];
                    }
                }

                $start = $offset;
                $textFound = true;

                continue;
            }

            if (!$textFound) {
                $start = $offset + 1;
            }
        }

        return $rows;
    }

    /**
     * The column layout a row terminator imposes, or null when the line is
     * ordinary row text.
     *
     * @return list<array{int, int}>|null
     */
    private function tableTerminatorColumns(ParserLine $line, int $indent, bool $border): ?array
    {
        if ($border) {
            return self::parseTableColumns(rtrim($this->content($line)), '=');
        }

        if ($line->blank || $line->indentWidth !== $indent) {
            return null;
        }

        $content = rtrim($this->content($line));

        if (1 !== preg_match(self::TABLE_SPAN_PATTERN, $content)) {
            return null;
        }

        return self::parseTableColumns($content, '-');
    }

    /**
     * @param list<ParserLine>      $lines
     * @param list<array{int, int}> $columns
     * @param list<array{int, int}> $rowColumns
     */
    private function buildTableRow(array $lines, int $indent, array $columns, array $rowColumns): ?TableRow
    {
        if ([] === $lines || [] === $rowColumns) {
            return null;
        }

        $colspans = self::tableColspans($columns, $rowColumns);

        if (null === $colspans) {
            $this->report(
                ProblemSeverity::Warning,
                'table/column-mismatch',
                'Simple table row separator does not line up with the top border; using the top border columns.',
                $this->spanOf($lines[0], self::lastNonBlank($lines) ?? $lines[0]),
            );

            $rowColumns = $columns;
            $colspans = array_fill(0, \count($columns), 1);
        }

        $this->checkTableMargins($lines, $indent, $rowColumns);

        $last = \count($rowColumns) - 1;
        $cells = [];

        foreach ($rowColumns as $index => $column) {
            $cells[] = $this->buildTableCell(
                $lines,
                $indent,
                $column[0],
                $index === $last ? null : $rowColumns[$index + 1][0],
                $colspans[$index],
            );
        }

        $lastLine = self::lastNonBlank($lines) ?? $lines[0];

        return new TableRow(ByteSpan::between($lines[0]->spanStart, $lastLine->end()), $cells);
    }

    /**
     * Maps a row separator's columns onto the table's columns and returns
     * the colspan of each cell, or null when the two do not line up. The
     * separator's last column always runs to the end of the table, because
     * the last column of a simple table is unbounded.
     *
     * @param list<array{int, int}> $columns
     * @param list<array{int, int}> $rowColumns
     *
     * @return list<int>|null
     */
    private static function tableColspans(array $columns, array $rowColumns): ?array
    {
        $total = \count($columns);
        $last = \count($rowColumns) - 1;
        $colspans = [];
        $index = 0;

        for ($position = 0; $position < $last; ++$position) {
            [$begin, $end] = $rowColumns[$position];

            if (!isset($columns[$index]) || $columns[$index][0] !== $begin) {
                return null;
            }

            $colspan = 1;

            while (isset($columns[$index]) && $columns[$index][1] !== $end) {
                ++$index;
                ++$colspan;
            }

            if (!isset($columns[$index])) {
                return null;
            }

            $colspans[] = $colspan;
            ++$index;
        }

        if (!isset($columns[$index])
            || $columns[$index][0] !== $rowColumns[$last][0]
            || $columns[$total - 1][1] !== $rowColumns[$last][1]
        ) {
            return null;
        }

        $colspans[] = $total - $index;

        return $colspans;
    }

    /**
     * Reports text written in the gap between two columns. The parser keeps
     * such text in the column to its left rather than dropping it.
     *
     * @param list<ParserLine>      $lines
     * @param list<array{int, int}> $rowColumns
     */
    private function checkTableMargins(array $lines, int $indent, array $rowColumns): void
    {
        $last = \count($rowColumns) - 1;

        foreach ($lines as $line) {
            for ($index = 0; $index < $last; ++$index) {
                $margin = $this->tableSlice($line, $indent, $rowColumns[$index][1], $rowColumns[$index + 1][0]);

                if ('' === trim($margin)) {
                    continue;
                }

                $this->report(
                    ProblemSeverity::Error,
                    'table/text-in-column-margin',
                    'Text starts in a simple table column margin; it stays in the column to its left.',
                    $this->spanOf($line, $line),
                );
            }
        }
    }

    /**
     * Cuts one cell out of the row lines and parses it as a nested region.
     * A null $to means the unbounded last column.
     *
     * @param list<ParserLine> $lines
     */
    private function buildTableCell(
        array $lines,
        int $indent,
        int $from,
        ?int $to,
        int $colspan,
        bool $segmentDiscontinuousText = false,
    ): TableCell {
        $bytes = $this->source->bytes;
        $cellLines = [];

        foreach ($lines as $line) {
            $start = $this->columnOffset($line, $indent, $from);
            $end = max($start, null === $to ? $line->end() : $this->columnOffset($line, $indent, $to));

            while ($end > $start && str_contains(" \t\v\f", $bytes[$end - 1])) {
                --$end;
            }

            $cellLines[] = ParserLine::fromLine(Line::scan($bytes, $line->line->index, $start, $end, ''));
        }

        $base = \PHP_INT_MAX;

        foreach ($cellLines as $cellLine) {
            if (!$cellLine->blank) {
                $base = min($base, $cellLine->indentWidth);
            }
        }

        $previousSegmentDiscontinuousText = $this->segmentDiscontinuousText;
        $this->segmentDiscontinuousText = $segmentDiscontinuousText;

        try {
            $children = $this->parseBlocks(new LineCursor($cellLines), \PHP_INT_MAX === $base ? 0 : $base);
        } finally {
            $this->segmentDiscontinuousText = $previousSegmentDiscontinuousText;
        }

        $lastLine = self::lastNonBlank($cellLines);
        $firstLine = self::firstNonBlank($cellLines) ?? $cellLines[0];

        return new TableCell(
            ByteSpan::between($firstLine->spanStart, $lastLine?->end() ?? $firstLine->spanStart),
            $children,
            $colspan,
        );
    }

    /**
     * The index of the first body row: every row that starts after the
     * head/body separator. A table without a separator, or whose rows all
     * sit above it, is all body.
     *
     * @param list<array{TableRow, int}> $rows
     */
    private static function firstBodyRow(array $rows, ?int $headSeparator): int
    {
        if (null === $headSeparator) {
            return 0;
        }

        foreach ($rows as $index => $row) {
            if ($row[1] > $headSeparator) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * The (begin, end) column pairs a border or span underline draws.
     *
     * @return list<array{int, int}>
     */
    private static function parseTableColumns(string $line, string $char): array
    {
        $columns = [];
        $offset = 0;

        while (false !== ($begin = strpos($line, $char, $offset))) {
            $offset = $begin + strspn($line, $char, $begin);
            $columns[] = [$begin, $offset];
        }

        return $columns;
    }

    private function tableSlice(ParserLine $line, int $indent, int $from, int $to): string
    {
        $start = $this->columnOffset($line, $indent, $from);
        $end = $this->columnOffset($line, $indent, $to);

        return substr($this->source->bytes, $start, max(0, $end - $start));
    }

    /**
     * The byte offset of a table column inside a line. Columns count code
     * points from the table's left edge; a column past the end of the line
     * resolves to its end, so a short line yields empty cells rather than
     * an error.
     */
    private function columnOffset(ParserLine $line, int $indent, int $column): int
    {
        $target = $indent + $column;

        if ($target < $line->indentWidth) {
            return max($line->spanStart, $line->contentStart - ($line->indentWidth - $target));
        }

        if ($target === $line->indentWidth) {
            return $line->contentStart;
        }

        $bytes = $this->source->bytes;
        $end = $line->end();
        $offset = $line->contentStart;

        for ($remaining = $target - $line->indentWidth; $remaining > 0 && $offset < $end; --$remaining) {
            ++$offset;

            while ($offset < $end && 0x80 === (\ord($bytes[$offset]) & 0xC0)) {
                ++$offset;
            }
        }

        return $offset;
    }

    /**
     * Explicit markup: directives, comments, targets, and reference
     * definitions.
     *
     * @return list<Node>
     */
    private function parseExplicitMarkup(LineCursor $cursor): array
    {
        $line0 = $cursor->peek();

        if (null === $line0) {
            return [];
        }

        $content = rtrim($this->content($line0));
        $rest = '..' === $content ? '' : ltrim(substr($content, 2));

        if ('' === $rest) {
            $next = $cursor->peek(1);
            $cursor->advance();

            if (null === $next || $next->blank) {
                return [new Comment(ByteSpan::between($line0->spanStart, $line0->end()), '')];
            }

            return [$this->makeComment($line0, $this->collectIndented($cursor, $line0->indentWidth), '')];
        }

        if (str_starts_with($rest, '_')) {
            $target = $this->tryParseTarget($cursor, $line0, $rest);

            if (null !== $target) {
                return [$target];
            }
        }

        if ('[' === $rest[0]) {
            if (
                1 === preg_match(self::REFERENCE_DEFINITION_PATTERN, $rest, $matches)
                && 1 === preg_match(self::REFERENCE_LABEL_PATTERN, $matches[1])
            ) {
                return [$this->parseReferenceDefinition($cursor, $line0, $rest, $matches[1])];
            }

            return [$this->parseMalformedDefinition(
                $cursor,
                $line0,
                $rest,
                'Malformed footnote or citation definition.',
            )];
        }

        if ('|' === $rest[0]) {
            if (
                1 === preg_match(self::SUBSTITUTION_DEFINITION_PATTERN, $rest, $matches)
                && '' !== trim($matches[1])
                && 1 === preg_match(self::DIRECTIVE_PATTERN, $matches[2], $directiveMatches)
            ) {
                return [$this->parseSubstitutionDefinition(
                    $cursor,
                    $line0,
                    $rest,
                    $matches[1],
                    $directiveMatches,
                )];
            }

            return [$this->parseMalformedDefinition(
                $cursor,
                $line0,
                $rest,
                'Malformed substitution definition.',
            )];
        }

        if (1 === preg_match(self::DIRECTIVE_PATTERN, $rest, $matches)) {
            return [$this->parseDirective($cursor, $line0, $matches)];
        }

        $cursor->advance();

        return [$this->makeComment($line0, $this->collectIndented($cursor, $line0->indentWidth), $rest)];
    }

    private function parseReferenceDefinition(
        LineCursor $cursor,
        ParserLine $line0,
        string $rest,
        string $label,
    ): FootnoteDefinition|CitationDefinition {
        $closingBracket = strpos($rest, ']');
        $contentStart = false === $closingBracket ? \strlen($rest) : $closingBracket + 1;
        $contentStart += strspn($rest, " \t", $contentStart);
        $firstContentStart = $contentStart < \strlen($rest)
            ? $this->explicitRestStart($line0) + $contentStart
            : null;

        [$body, $end] = $this->parseDefinitionBody($cursor, $line0, $firstContentStart);
        $span = ByteSpan::between($line0->spanStart, $end);

        if (self::isFootnoteLabel($label)) {
            return new FootnoteDefinition($span, $label, $body);
        }

        return new CitationDefinition($span, $label, $body);
    }

    /**
     * @param array{0: string, 1: string, 2?: string} $directiveMatches
     */
    private function parseSubstitutionDefinition(
        LineCursor $cursor,
        ParserLine $line0,
        string $rest,
        string $name,
        array $directiveMatches,
    ): SubstitutionDefinition {
        $closingPipe = strpos($rest, '|', 1);
        $directiveOffset = false === $closingPipe ? \strlen($rest) : $closingPipe + 1;
        $directiveOffset += strspn($rest, " \t", $directiveOffset);
        $directiveStart = $this->explicitRestStart($line0) + $directiveOffset;

        $cursor->advance();
        $run = $this->collectIndented($cursor, $line0->indentWidth);
        $virtual = new ParserLine(
            $line0->line,
            $line0->indentWidth,
            $directiveStart,
            $directiveStart,
            false,
        );
        $directive = $this->parseDirective(
            new LineCursor([$virtual, ...$run]),
            $virtual,
            $directiveMatches,
        );
        $lastLine = self::lastNonBlank($run) ?? $line0;

        return new SubstitutionDefinition(
            ByteSpan::between($line0->spanStart, $lastLine->end()),
            $name,
            $directive,
        );
    }

    private function parseMalformedDefinition(
        LineCursor $cursor,
        ParserLine $line0,
        string $rest,
        string $message,
    ): Comment {
        $this->report(
            ProblemSeverity::Warning,
            'reference/malformed-definition',
            $message,
            $this->spanOf($line0, $line0),
        );
        $cursor->advance();

        return $this->makeComment(
            $line0,
            $this->collectIndented($cursor, $line0->indentWidth),
            $rest,
        );
    }

    /**
     * @return array{list<Node>, int}
     */
    private function parseDefinitionBody(
        LineCursor $cursor,
        ParserLine $line0,
        ?int $firstContentStart,
    ): array {
        $cursor->advance();
        $run = $this->collectIndented($cursor, $line0->indentWidth);
        $base = $line0->indentWidth + 3;

        foreach ($run as $line) {
            if (!$line->blank) {
                $base = min($base, $line->indentWidth);
            }
        }

        if (null !== $firstContentStart) {
            $virtual = new ParserLine($line0->line, $base, $firstContentStart, $firstContentStart, false);
            $body = $this->parseBlocks(new LineCursor([$virtual, ...$run]), $base);
        } elseif ([] !== $run) {
            $body = $this->parseBlocks(new LineCursor($run), $base);
        } else {
            $body = [];
        }

        $lastLine = self::lastNonBlank($run) ?? $line0;

        return [$body, $lastLine->end()];
    }

    private function explicitRestStart(ParserLine $line): int
    {
        $offset = $line->contentStart + 2;

        return $offset + strspn($this->source->bytes, " \t", $offset, $line->end() - $offset);
    }

    private static function isFootnoteLabel(string $label): bool
    {
        return '*' === $label || str_starts_with($label, '#') || ctype_digit($label);
    }

    /**
     * @param list<ParserLine> $run
     */
    private function makeComment(ParserLine $line0, array $run, string $rest): Comment
    {
        $parts = [];

        if ('' !== $rest) {
            $parts[] = $rest;
        }

        foreach ($run as $line) {
            $parts[] = $line->blank ? '' : rtrim($this->content($line));
        }

        $lastLine = self::lastNonBlank($run) ?? $line0;

        return new Comment(ByteSpan::between($line0->spanStart, $lastLine->end()), implode("\n", $parts));
    }

    private function tryParseTarget(LineCursor $cursor, ParserLine $line0, string $rest): ?HyperlinkTarget
    {
        $name = '';
        $target = '';
        $anonymous = false;

        if ('__:' === $rest) {
            $anonymous = true;
        } elseif (str_starts_with($rest, '__: ')) {
            $anonymous = true;
            $target = trim(substr($rest, 4));
        } elseif (1 === preg_match('/^_`([^`]+)`:(?:[ \t]+(.*))?$/', $rest, $matches)) {
            $name = $matches[1];
            $target = trim($matches[2] ?? '');
        } elseif (1 === preg_match('/^_((?:\\\\.|[^\\\\:`])+):(?:[ \t]+(.*))?$/', $rest, $matches)) {
            $name = preg_replace('/\\\\(.)/', '$1', $matches[1]) ?? $matches[1];
            $target = trim($matches[2] ?? '');
        } else {
            return null;
        }

        $cursor->advance();
        $run = $this->collectIndented($cursor, $line0->indentWidth);

        foreach ($run as $line) {
            if (!$line->blank) {
                $target .= trim($this->content($line));
            }
        }

        $lastLine = self::lastNonBlank($run) ?? $line0;

        return new HyperlinkTarget(
            ByteSpan::between($line0->spanStart, $lastLine->end()),
            $anonymous ? '' : $name,
            $target,
            $anonymous,
        );
    }

    private function parseAnonymousShortTarget(LineCursor $cursor, ParserLine $line, string $content): HyperlinkTarget
    {
        $cursor->advance();
        $target = '__' === $content ? '' : trim(substr($content, 3));

        return new HyperlinkTarget(ByteSpan::between($line->spanStart, $line->end()), '', $target, true);
    }

    /**
     * @param array{0: string, 1: string, 2?: string} $matches
     */
    private function parseDirective(LineCursor $cursor, ParserLine $line0, array $matches): Directive
    {
        $name = $matches[1];
        $argumentText = trim($matches[2] ?? '');
        $arguments = '' === $argumentText ? [] : [$argumentText];

        $cursor->advance();
        $run = $this->collectIndented($cursor, $line0->indentWidth);

        [$options, $bodyStart] = $this->parseDirectiveOptions($run);

        while ($bodyStart < \count($run) && $run[$bodyStart]->blank) {
            ++$bodyStart;
        }

        $bodyLines = \array_slice($run, $bodyStart);
        $rawBody = null;
        $body = [];
        $spec = $this->profile->directives->get($name);
        $bodyKind = null === $spec ? DirectiveBodyKind::Opaque : $spec->bodyKind;

        if ([] !== $bodyLines) {
            $bodyLast = self::lastNonBlank($bodyLines) ?? $bodyLines[0];
            $rawBody = ByteSpan::between($bodyLines[0]->line->span->start, $bodyLast->end());

            if (DirectiveBodyKind::Blocks === $bodyKind) {
                $base = \PHP_INT_MAX;

                foreach ($bodyLines as $bodyLine) {
                    if (!$bodyLine->blank) {
                        $base = min($base, $bodyLine->indentWidth);
                    }
                }

                $body = $this->parseBlocks(new LineCursor($bodyLines), $base);
            }
        }

        $lastLine = self::lastNonBlank($run) ?? $line0;

        return new Directive(
            ByteSpan::between($line0->spanStart, $lastLine->end()),
            $name,
            $arguments,
            $options,
            $rawBody,
            $bodyKind,
            $body,
        );
    }

    /**
     * Parses the leading ":name: value" fields of a directive block.
     * Returns the ordered options and the index where the body starts.
     *
     * @param list<ParserLine> $run
     *
     * @return array{array<string, string>, int}
     */
    private function parseDirectiveOptions(array $run): array
    {
        $index = 0;
        $count = \count($run);

        while ($index < $count && $run[$index]->blank) {
            ++$index;
        }

        if ($index >= $count) {
            return [[], $index];
        }

        $firstContent = rtrim($this->content($run[$index]));

        if (!str_starts_with($firstContent, ':') || 1 !== preg_match(self::FIELD_PATTERN, $firstContent)) {
            return [[], $index];
        }

        /** @var array<string, string> $options */
        $options = [];

        while ($index < $count) {
            $line = $run[$index];

            if ($line->blank) {
                ++$index;

                break;
            }

            $content = rtrim($this->content($line));

            if (!str_starts_with($content, ':') || 1 !== preg_match(self::FIELD_PATTERN, $content, $matches)) {
                $this->report(
                    ProblemSeverity::Warning,
                    'directive/invalid-option-block',
                    'Directive option block ends at a line that is not a ":name: value" field; treating the rest as content.',
                    $this->spanOf($line, $line),
                );

                break;
            }

            $name = trim(preg_replace('/\\\\(.)/', '$1', $matches[1]) ?? $matches[1]);

            if ('' === $name || 1 === preg_match('/^\d+$/', $name)) {
                $this->report(
                    ProblemSeverity::Warning,
                    'directive/invalid-option-block',
                    'Directive option name must be a non-numeric string; treating the rest as content.',
                    $this->spanOf($line, $line),
                );

                break;
            }

            $value = trim($matches[2] ?? '');
            ++$index;

            while ($index < $count && !$run[$index]->blank && $run[$index]->indentWidth > $line->indentWidth) {
                $continuation = rtrim($this->content($run[$index]));
                $value = '' === $value ? $continuation : $value."\n".$continuation;
                ++$index;
            }

            if (\array_key_exists($name, $options)) {
                $this->report(
                    ProblemSeverity::Warning,
                    'directive/duplicate-option',
                    \sprintf('Directive option "%s" appears more than once; the last value wins.', $name),
                    $this->spanOf($line, $line),
                );
            }

            $options[$name] = $value;
        }

        return [$options, $index];
    }

    /**
     * Collects the run of lines that are blank or indented deeper than the
     * given context, with trailing blank lines dropped.
     *
     * @return list<ParserLine>
     */
    private function collectIndented(LineCursor $cursor, int $contextIndent): array
    {
        $collected = [];

        while (null !== ($line = $cursor->peek()) && ($line->blank || $line->indentWidth > $contextIndent)) {
            $collected[] = $line;
            $cursor->advance();
        }

        while ([] !== $collected && $collected[\count($collected) - 1]->blank) {
            array_pop($collected);
        }

        return $collected;
    }

    /**
     * @param list<ParserLine> $lines
     */
    private static function firstNonBlank(array $lines): ?ParserLine
    {
        foreach ($lines as $line) {
            if (!$line->blank) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @param list<ParserLine> $lines
     */
    private static function lastNonBlank(array $lines): ?ParserLine
    {
        for ($index = \count($lines) - 1; $index >= 0; --$index) {
            if (!$lines[$index]->blank) {
                return $lines[$index];
            }
        }

        return null;
    }

    private function content(ParserLine $line): string
    {
        return $line->contentIn($this->source->bytes);
    }

    private function spanOf(ParserLine $first, ParserLine $last): ByteSpan
    {
        return ByteSpan::between($first->spanStart, $last->end());
    }

    private function report(ProblemSeverity $severity, string $code, string $message, ?ByteSpan $span): void
    {
        $this->problems->add(new Problem($severity, $code, $message, $span));
    }

    private static function isAdornment(string $content): bool
    {
        if ('' === $content) {
            return false;
        }

        $char = $content[0];

        return str_contains(self::ADORNMENT_CHARS, $char) && \strlen($content) === strspn($content, $char);
    }

    private static function bulletMarker(string $content): ?string
    {
        foreach (self::BULLET_MARKERS as $marker) {
            if (!str_starts_with($content, $marker)) {
                continue;
            }

            $length = \strlen($marker);

            if (\strlen($content) === $length || ' ' === $content[$length] || "\t" === $content[$length]) {
                return $marker;
            }
        }

        return null;
    }

    /**
     * Matches the docutils arabic, alphabetic, Roman, and auto enumerators.
     * A first "i" or "I" starts a Roman list; within an established alpha
     * list it keeps the expected alphabetic sequence.
     *
     * @return array{marker: string, value: int|null, format: string, style: EnumerationStyle|null}|null
     */
    private static function enumerator(string $content, ?EnumerationStyle $expectedStyle = null): ?array
    {
        if ('' === $content) {
            return null;
        }

        $first = $content[0];

        if (
            '(' !== $first
            && '#' !== $first
            && ($first < '0' || $first > '9')
            && ($first < 'a' || $first > 'z')
            && ($first < 'A' || $first > 'Z')
        ) {
            return null;
        }

        $valuePattern = '(#|\d{1,4}|[a-z]|[A-Z]|[ivxlcdm]+|[IVXLCDM]+)';

        if (1 === preg_match('/^\('.$valuePattern.'\)/', $content, $matches)) {
            $format = 'parens';
        } elseif (1 === preg_match('/^'.$valuePattern.'([.)])/', $content, $matches)) {
            $format = '.' === $matches[2] ? 'period' : 'paren';
        } else {
            return null;
        }

        $marker = $matches[0];
        $length = \strlen($marker);

        if (\strlen($content) > $length && ' ' !== $content[$length] && "\t" !== $content[$length]) {
            return null;
        }

        [$style, $value] = self::enumeratorValue($matches[1], $expectedStyle);

        if ('#' !== $matches[1] && (null === $style || null === $value)) {
            return null;
        }

        return [
            'marker' => $marker,
            'value' => $value,
            'format' => $format,
            'style' => $style,
        ];
    }

    /**
     * @return array{EnumerationStyle|null, int|null}
     */
    private static function enumeratorValue(string $value, ?EnumerationStyle $expectedStyle): array
    {
        if ('#' === $value) {
            return [null, null];
        }

        if (null !== $expectedStyle) {
            $ordinal = self::ordinalForStyle($value, $expectedStyle);

            if (null !== $ordinal) {
                return [$expectedStyle, $ordinal];
            }
        }

        if (ctype_digit($value)) {
            return [EnumerationStyle::Arabic, (int) $value];
        }

        if ('i' === $value || 'I' === $value) {
            return [
                'i' === $value ? EnumerationStyle::LowerRoman : EnumerationStyle::UpperRoman,
                1,
            ];
        }

        if (1 === \strlen($value) && ctype_lower($value)) {
            return [EnumerationStyle::LowerAlpha, ord($value) - ord('a') + 1];
        }

        if (1 === \strlen($value) && ctype_upper($value)) {
            return [EnumerationStyle::UpperAlpha, ord($value) - ord('A') + 1];
        }

        $style = ctype_lower($value) ? EnumerationStyle::LowerRoman : EnumerationStyle::UpperRoman;

        return [$style, self::romanOrdinal($value)];
    }

    private static function ordinalForStyle(string $value, EnumerationStyle $style): ?int
    {
        return match ($style) {
            EnumerationStyle::Arabic => ctype_digit($value) ? (int) $value : null,
            EnumerationStyle::LowerAlpha => 1 === \strlen($value) && ctype_lower($value)
                ? ord($value) - ord('a') + 1
                : null,
            EnumerationStyle::UpperAlpha => 1 === \strlen($value) && ctype_upper($value)
                ? ord($value) - ord('A') + 1
                : null,
            EnumerationStyle::LowerRoman => ctype_lower($value) ? self::romanOrdinal($value) : null,
            EnumerationStyle::UpperRoman => ctype_upper($value) ? self::romanOrdinal($value) : null,
        };
    }

    private static function romanOrdinal(string $value): ?int
    {
        $upper = strtoupper($value);

        if (1 !== preg_match('/^M{0,4}(CM|CD|D?C{0,3})(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$/', $upper)) {
            return null;
        }

        $values = ['I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100, 'D' => 500, 'M' => 1000];
        $total = 0;
        $previous = 0;

        for ($index = \strlen($upper) - 1; $index >= 0; --$index) {
            $current = $values[$upper[$index]];

            if ($current < $previous) {
                $total -= $current;
            } else {
                $total += $current;
                $previous = $current;
            }
        }

        return $total > 0 ? $total : null;
    }

    /**
     * Shapes of out-of-scope constructs whose silent misparse would
     * surprise: field lists and line blocks.
     */
    private static function unsupportedShape(string $content): ?string
    {
        if ('' === $content) {
            return null;
        }

        $first = $content[0];

        if (':' === $first && (1 === preg_match(self::FIELD_PATTERN, $content) || 1 === preg_match('/^:((?:\\\\.|[^\\\\:])+):[ \t]/', $content))) {
            return 'field list';
        }

        if ('|' === $first && ('|' === $content || str_starts_with($content, '| '))) {
            return 'line block';
        }

        return null;
    }

    /**
     * Counts UTF-8 code points; adornment length checks compare characters,
     * not bytes.
     */
    private static function charLength(string $text): int
    {
        $count = 0;

        for ($index = 0, $length = \strlen($text); $index < $length; ++$index) {
            $byte = \ord($text[$index]);

            if ($byte < 0x80 || $byte > 0xBF) {
                ++$count;
            }
        }

        return $count;
    }
}
