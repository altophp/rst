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

use Alto\Rst\Node\Inline\CitationReference;
use Alto\Rst\Node\Inline\Emphasis;
use Alto\Rst\Node\Inline\FootnoteReference;
use Alto\Rst\Node\Inline\HyperlinkReference;
use Alto\Rst\Node\Inline\InlineLiteral;
use Alto\Rst\Node\Inline\InlineTarget;
use Alto\Rst\Node\Inline\InlineText;
use Alto\Rst\Node\Inline\InterpretedText;
use Alto\Rst\Node\Inline\StandaloneHyperlink;
use Alto\Rst\Node\Inline\Strong;
use Alto\Rst\Node\Inline\SubstitutionReference;
use Alto\Rst\Node\Node;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Source\ByteSpan;

/**
 * The inline pass: turns a contiguous source slice into inline nodes.
 *
 * The slice starts at $baseOffset in the original input, so every emitted
 * span is `$baseOffset + position in the slice`; there is no offset table.
 * Malformed markup never throws: it degrades to text plus a problem in the
 * `inline/` area, and the scan continues after the offending run.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class InlineParser
{
    private const string WHITESPACE = " \t\n\r\v\f";

    /**
     * Characters allowed immediately before a start-string, next to
     * whitespace and the start of the slice.
     */
    private const string START_BEFORE = '-:/\'"<([{';

    /**
     * Characters allowed immediately after an end-string, next to
     * whitespace and the end of the slice.
     */
    private const string END_AFTER = '-.,:;!?\\/\'")]}>';

    /**
     * Opening delimiters and the closing delimiter each one matches.
     */
    private const array MATCHING = ['"' => '"', '\'' => '\'', '(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    /**
     * The docutils simple name, approximated over bytes: any byte at or
     * above 0x80 counts as a word byte, which keeps UTF-8 names whole
     * without a Unicode-aware pass.
     */
    private const string NAME = '(?:[0-9A-Za-z\x80-\xff]+(?:[-._+:][0-9A-Za-z\x80-\xff]+)*)';

    /**
     * The bytes the plain-text fast path must stop at: escapes, whitespace
     * (normalized to one space), and the START_BEFORE set, because the byte
     * after any of those has start context and must be offered to
     * matchConstruct. Every other byte can never begin a construct when its
     * predecessor is plain text, so whole words are copied in one substr.
     */
    private const string PLAIN_STOP = '\\'.self::WHITESPACE.self::START_BEFORE;

    /**
     * The end-string context, as a lookahead.
     */
    private const string END = '(?=[\s\-.,:;!?\\\\/\'"\)\]\}>]|\z)';

    /**
     * Suffixes a closing backquote accepts: a trailing role, or a reference
     * marker. Longest alternative first.
     */
    private const string BACKQUOTE_SUFFIX = '(?::'.self::NAME.':|__|_)';

    private const array SCHEMES = ['https://', 'http://', 'ftp://', 'mailto:'];

    /**
     * Bytes that always terminate a standalone URI.
     */
    private const string URI_STOP = '<>"{}|\\^`';

    /**
     * Closing brackets that end a standalone URI unless their opener is
     * part of it.
     */
    private const array URI_BRACKETS = [')' => '(', ']' => '[', '}' => '{'];

    public function __construct(
        private ProblemCollector $problems,
    ) {
    }

    /**
     * @return list<Node>
     */
    public function parse(string $text, int $baseOffset): array
    {
        $nodes = [];
        $length = \strlen($text);
        $buffer = '';
        $runStart = 0;
        $offset = 0;

        /**
         * Start positions at or past which an end-string scan is known to
         * fail, keyed by mark and suffix; see findEndString.
         *
         * @var array<string, int> $noEnd
         */
        $noEnd = [];

        while ($offset < $length) {
            $char = $text[$offset];

            if ('\\' === $char) {
                [$decoded, $offset] = $this->readEscape($text, $offset);
                $buffer .= $decoded;

                continue;
            }

            if ($this->isWhitespace($char)) {
                $buffer .= ' ';
                $offset += strspn($text, self::WHITESPACE, $offset);

                continue;
            }

            $matched = $this->matchConstruct($text, $offset, $baseOffset, $noEnd);

            if (null === $matched) {
                // Copy the whole plain run at once: no byte can begin a
                // construct until its predecessor is whitespace or one of
                // the START_BEFORE bytes again, and a stop byte itself is
                // copied alone so the byte after it reaches matchConstruct.
                $run = str_contains(self::PLAIN_STOP, $char)
                    ? 1
                    : 1 + strcspn($text, self::PLAIN_STOP, $offset + 1);

                $buffer .= substr($text, $offset, $run);
                $offset += $run;

                continue;
            }

            if ('' !== $buffer) {
                $nodes[] = new InlineText(ByteSpan::between($baseOffset + $runStart, $baseOffset + $offset), $buffer);
                $buffer = '';
            }

            $nodes[] = $matched[0];
            $offset = $matched[1];
            $runStart = $offset;
        }

        if ('' !== $buffer) {
            $nodes[] = new InlineText(ByteSpan::between($baseOffset + $runStart, $baseOffset + $length), $buffer);
        }

        return $nodes;
    }

    /**
     * @param array<string, int> $noEnd
     *
     * @return array{0: Node, 1: int}|null
     */
    private function matchConstruct(string $text, int $offset, int $baseOffset, array &$noEnd): ?array
    {
        if (!$this->hasStartContext($text, $offset)) {
            return null;
        }

        return match ($text[$offset]) {
            '`' => $this->matchBackquote($text, $offset, $baseOffset, $noEnd),
            '*' => $this->matchStar($text, $offset, $baseOffset, $noEnd),
            '|' => $this->matchSubstitution($text, $offset, $baseOffset, $noEnd),
            '_' => $this->matchInlineTarget($text, $offset, $baseOffset, $noEnd),
            ':' => $this->matchRolePrefix($text, $offset, $baseOffset, $noEnd),
            '[' => $this->matchBracket($text, $offset, $baseOffset),
            default => $this->matchWord($text, $offset, $baseOffset),
        };
    }

    /**
     * @param array<string, int> $noEnd
     *
     * @return array{0: Node, 1: int}|null
     */
    private function matchStar(string $text, int $offset, int $baseOffset, array &$noEnd): ?array
    {
        $mark = str_starts_with(substr($text, $offset, 2), '**') ? '**' : '*';
        $contentStart = $offset + \strlen($mark);

        if (!$this->opensContent($text, $offset, $contentStart)) {
            return null;
        }

        $end = $this->findEndString($text, $contentStart, $mark, $noEnd);

        if (null === $end) {
            $this->report(
                'inline/unmatched-start-string',
                \sprintf('Inline %s start-string without end-string.', '**' === $mark ? 'strong' : 'emphasis'),
                ByteSpan::of($baseOffset + $offset, \strlen($mark)),
            );

            return null;
        }

        $next = $end['end'] + \strlen($mark);
        $span = ByteSpan::between($baseOffset + $offset, $baseOffset + $next);
        $children = $this->parse(substr($text, $contentStart, $end['end'] - $contentStart), $baseOffset + $contentStart);
        $this->reportNestedMarkup($children, $span);

        return ['**' === $mark ? new Strong($span, $children) : new Emphasis($span, $children), $next];
    }

    /**
     * @param array<string, int> $noEnd
     *
     * @return array{0: Node, 1: int}|null
     */
    private function matchBackquote(string $text, int $offset, int $baseOffset, array &$noEnd): ?array
    {
        if (str_starts_with(substr($text, $offset, 2), '``')) {
            return $this->matchLiteral($text, $offset, $baseOffset, $noEnd);
        }

        return $this->matchPhrase($text, $offset, $offset + 1, null, $baseOffset, $noEnd);
    }

    /**
     * @param array<string, int> $noEnd
     *
     * @return array{0: Node, 1: int}|null
     */
    private function matchLiteral(string $text, int $offset, int $baseOffset, array &$noEnd): ?array
    {
        $contentStart = $offset + 2;

        if (!$this->opensContent($text, $offset, $contentStart)) {
            return null;
        }

        $end = $this->findEndString($text, $contentStart, '``', $noEnd, '', false);

        if (null === $end) {
            $this->report(
                'inline/unclosed-literal',
                'Inline literal start-string without end-string.',
                ByteSpan::of($baseOffset + $offset, 2),
            );

            return null;
        }

        $next = $end['end'] + 2;
        $content = $this->joinLiteralLines(substr($text, $contentStart, $end['end'] - $contentStart));

        return [new InlineLiteral(ByteSpan::between($baseOffset + $offset, $baseOffset + $next), $content), $next];
    }

    /**
     * Interpreted text and phrase hyperlink references share a start-string:
     * the suffix after the closing backquote decides which one it is.
     *
     * @param array<string, int> $noEnd
     *
     * @return array{0: Node, 1: int}|null
     */
    private function matchPhrase(string $text, int $offset, int $contentStart, ?string $role, int $baseOffset, array &$noEnd): ?array
    {
        if (!$this->opensContent($text, $offset, $contentStart)) {
            return null;
        }

        $end = $this->findEndString($text, $contentStart, '`', $noEnd, self::BACKQUOTE_SUFFIX);

        if (null === $end) {
            // A role prefix stays silent: the rescan reaches the backquote
            // on its own and reports the missing end-string once.
            if (null === $role) {
                $this->report(
                    'inline/unmatched-start-string',
                    'Inline interpreted text or phrase reference start-string without end-string.',
                    ByteSpan::between($baseOffset + $offset, $baseOffset + $contentStart),
                );
            }

            return null;
        }

        $suffix = $end['suffix'];
        $next = $end['end'] + 1 + \strlen($suffix);
        $span = ByteSpan::between($baseOffset + $offset, $baseOffset + $next);
        $content = substr($text, $contentStart, $end['end'] - $contentStart);

        if ('' !== $suffix && null !== $role) {
            $this->report(
                'inline/malformed-role',
                'Interpreted text carries a role prefix and a suffix at once.',
                $span,
            );

            return null;
        }

        if ('_' === $suffix || '__' === $suffix) {
            $reference = $this->buildHyperlink($span, $content, '__' === $suffix);

            return null === $reference ? null : [$reference, $next];
        }

        if ('' !== $suffix) {
            return [new InterpretedText($span, trim($suffix, ':'), $this->decode($content)), $next];
        }

        return [new InterpretedText($span, $role, $this->decode($content), null !== $role), $next];
    }

    /**
     * @param array<string, int> $noEnd
     *
     * @return array{0: Node, 1: int}|null
     */
    private function matchRolePrefix(string $text, int $offset, int $baseOffset, array &$noEnd): ?array
    {
        if (1 !== preg_match('~:('.self::NAME.'):(?=`)~A', $this->wordWindow($text, $offset), $matches)) {
            return null;
        }

        $backquote = $offset + \strlen($matches[0]);

        if (str_starts_with(substr($text, $backquote, 2), '``')) {
            return null;
        }

        return $this->matchPhrase($text, $offset, $backquote + 1, $matches[1], $baseOffset, $noEnd);
    }

    /**
     * @param array<string, int> $noEnd
     *
     * @return array{0: Node, 1: int}|null
     */
    private function matchSubstitution(string $text, int $offset, int $baseOffset, array &$noEnd): ?array
    {
        $contentStart = $offset + 1;

        if (str_starts_with(substr($text, $contentStart, 1), '|')) {
            return null;
        }

        if (!$this->opensContent($text, $offset, $contentStart)) {
            return null;
        }

        $end = $this->findEndString($text, $contentStart, '|', $noEnd, '(?:__|_)');

        if (null === $end) {
            $this->report(
                'inline/unmatched-start-string',
                'Substitution reference start-string without end-string.',
                ByteSpan::of($baseOffset + $offset, 1),
            );

            return null;
        }

        $next = $end['end'] + 1 + \strlen($end['suffix']);
        $name = $this->decode(substr($text, $contentStart, $end['end'] - $contentStart));
        $span = ByteSpan::between($baseOffset + $offset, $baseOffset + $next);

        return [new SubstitutionReference(
            $span,
            $name,
            '' !== $end['suffix'],
            '__' === $end['suffix'],
        ), $next];
    }

    /**
     * @param array<string, int> $noEnd
     *
     * @return array{0: Node, 1: int}|null
     */
    private function matchInlineTarget(string $text, int $offset, int $baseOffset, array &$noEnd): ?array
    {
        if (!str_starts_with(substr($text, $offset, 2), '_`')) {
            return null;
        }

        $contentStart = $offset + 2;

        if (!$this->opensContent($text, $offset, $contentStart)) {
            return null;
        }

        $end = $this->findEndString($text, $contentStart, '`', $noEnd);

        if (null === $end) {
            $this->report(
                'inline/unmatched-start-string',
                'Inline target start-string without end-string.',
                ByteSpan::of($baseOffset + $offset, 2),
            );

            return null;
        }

        $next = $end['end'] + 1;
        $name = $this->decode(substr($text, $contentStart, $end['end'] - $contentStart));

        return [new InlineTarget(ByteSpan::between($baseOffset + $offset, $baseOffset + $next), $name), $next];
    }

    /**
     * The whitespace-free run starting at $offset. The name, footnote,
     * citation, role, and suffix patterns cannot match whitespace, and
     * their END lookahead accepts end-of-subject exactly where it accepts
     * whitespace, so matching against this window is equivalent to
     * matching against the full text at $offset. It is also much faster: a
     * preg_match with a start offset costs time proportional to the whole
     * subject length, which made every word of a large slice pay for the
     * slice's size.
     */
    private function wordWindow(string $text, int $offset): string
    {
        return substr($text, $offset, strcspn($text, self::WHITESPACE, $offset));
    }

    /**
     * @return array{0: Node, 1: int}|null
     */
    private function matchBracket(string $text, int $offset, int $baseOffset): ?array
    {
        $window = $this->wordWindow($text, $offset);
        $footnote = '~\[([0-9]+|#'.self::NAME.'?|\*)\]_'.self::END.'~A';

        if (1 === preg_match($footnote, $window, $matches)) {
            $next = $offset + \strlen($matches[0]);
            $span = ByteSpan::between($baseOffset + $offset, $baseOffset + $next);

            return [new FootnoteReference($span, $matches[1]), $next];
        }

        $citation = '~\[('.self::NAME.')\]_'.self::END.'~A';

        if (1 === preg_match($citation, $window, $matches)) {
            $next = $offset + \strlen($matches[0]);
            $span = ByteSpan::between($baseOffset + $offset, $baseOffset + $next);

            return [new CitationReference($span, $matches[1]), $next];
        }

        if (str_starts_with(substr($text, $offset, 3), '[]_')) {
            $this->report(
                'inline/empty-reference',
                'Footnote or citation reference without a label.',
                ByteSpan::of($baseOffset + $offset, 3),
            );
        }

        return null;
    }

    /**
     * @return array{0: Node, 1: int}|null
     */
    private function matchWord(string $text, int $offset, int $baseOffset): ?array
    {
        $char = $text[$offset];

        if (!ctype_alnum($char) && \ord($char) < 0x80) {
            return null;
        }

        return $this->matchStandaloneUri($text, $offset, $baseOffset)
            ?? $this->matchSimpleReference($text, $offset, $baseOffset);
    }

    /**
     * @return array{0: Node, 1: int}|null
     */
    private function matchSimpleReference(string $text, int $offset, int $baseOffset): ?array
    {
        $window = $this->wordWindow($text, $offset);

        // A simple reference always carries a trailing underscore; most
        // words of plain prose carry none, so skip the pattern for them.
        if (!str_contains($window, '_')) {
            return null;
        }

        if (1 !== preg_match('~'.self::NAME.'(__?)'.self::END.'~A', $window, $matches)) {
            return null;
        }

        $next = $offset + \strlen($matches[0]);
        $name = substr($matches[0], 0, -\strlen($matches[1]));
        $span = ByteSpan::between($baseOffset + $offset, $baseOffset + $next);

        return [new HyperlinkReference($span, $name, null, '__' === $matches[1], true), $next];
    }

    /**
     * @return array{0: Node, 1: int}|null
     */
    private function matchStandaloneUri(string $text, int $offset, int $baseOffset): ?array
    {
        // Every scheme starts with h, f, or m; skip the comparisons for
        // any other first byte, which is nearly every word of plain prose.
        $first = $text[$offset] | "\x20";

        if ('h' !== $first && 'f' !== $first && 'm' !== $first) {
            return null;
        }

        $scheme = null;

        foreach (self::SCHEMES as $candidate) {
            $candidateLength = \strlen($candidate);

            if ($offset + $candidateLength <= \strlen($text)
                && 0 === substr_compare($text, $candidate, $offset, $candidateLength, true)
            ) {
                $scheme = $candidate;

                break;
            }
        }

        if (null === $scheme) {
            return null;
        }

        $length = \strlen($text);
        $bodyStart = $offset + \strlen($scheme);
        $end = $bodyStart;

        while ($end < $length && !$this->isWhitespace($text[$end]) && !str_contains(self::URI_STOP, $text[$end])) {
            ++$end;
        }

        $end = $this->trimUriTail($text, $offset, $bodyStart, $end);

        if ($end === $bodyStart) {
            return null;
        }

        $span = ByteSpan::between($baseOffset + $offset, $baseOffset + $end);

        return [new StandaloneHyperlink($span, substr($text, $offset, $end - $offset)), $end];
    }

    /**
     * Drops trailing sentence punctuation and closing brackets the URI does
     * not open itself, so "(see https://example.com/a_(b))." keeps the inner
     * pair and leaves the sentence punctuation outside.
     */
    private function trimUriTail(string $text, int $start, int $bodyStart, int $end): int
    {
        while ($end > $bodyStart) {
            $last = $text[$end - 1];

            if (str_contains('.,;:!?', $last)) {
                --$end;

                continue;
            }

            $opener = self::URI_BRACKETS[$last] ?? null;

            if (null === $opener) {
                break;
            }

            $head = substr($text, $start, $end - 1 - $start);

            if (substr_count($head, $opener) > substr_count($head, $last)) {
                break;
            }

            --$end;
        }

        return $end;
    }

    private function buildHyperlink(ByteSpan $span, string $content, bool $anonymous): ?HyperlinkReference
    {
        $uri = null;
        $text = $content;

        if (1 === preg_match('~(?:^|[ \t\n])<([^<>]+)>\z~', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $uri = (string) preg_replace('~\s+~', '', $matches[1][0]);
            $text = substr($content, 0, $matches[0][1]);
        }

        $text = rtrim($this->decode($text));

        if ('' === $text) {
            $text = $uri ?? '';
        }

        if ('' === $text) {
            $this->report('inline/empty-reference', 'Hyperlink reference without text or URI.', $span);

            return null;
        }

        return new HyperlinkReference($span, $text, $uri, $anonymous);
    }

    /**
     * Locates the first end-string that satisfies the docutils end-string
     * rules, together with the suffix it carries.
     *
     * $noEnd remembers failed scans per mark, suffix, and escape mode: a
     * scan that found no end-string anywhere past $from cannot succeed from
     * any later start either, because whether an offset is a valid
     * end-string does not depend on where the scan began. With escape
     * processing on, that equivalence only holds when no backslash follows
     * $from (a different start could pair escapes differently), so the
     * failure is recorded only then. Without the cache, a run of unclosed
     * start-strings rescanned the tail once per start, which made such
     * input quadratic.
     *
     * @param array<string, int> $noEnd
     *
     * @return array{end: int, suffix: string}|null
     */
    private function findEndString(string $text, int $from, string $mark, array &$noEnd, string $suffix = '', bool $escapes = true): ?array
    {
        $key = $mark.'|'.$suffix.'|'.($escapes ? 'e' : 'r');

        if (isset($noEnd[$key]) && $from >= $noEnd[$key]) {
            return null;
        }

        $length = \strlen($text);
        $markLength = \strlen($mark);
        $offset = $from;

        while ($offset + $markLength <= $length) {
            if ($escapes && '\\' === $text[$offset]) {
                $offset += 2;

                continue;
            }

            if (substr($text, $offset, $markLength) !== $mark || $offset === $from || $this->isWhitespace($text[$offset - 1])) {
                ++$offset;

                continue;
            }

            $after = $offset + $markLength;

            foreach ($this->suffixCandidates($text, $after, $suffix) as $candidate) {
                if ($this->hasEndContext($text, $after + \strlen($candidate))) {
                    return ['end' => $offset, 'suffix' => $candidate];
                }
            }

            ++$offset;
        }

        if (!$escapes || false === strpos($text, '\\', $from)) {
            $noEnd[$key] = min($noEnd[$key] ?? \PHP_INT_MAX, $from);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function suffixCandidates(string $text, int $offset, string $pattern): array
    {
        if ('' === $pattern) {
            return [''];
        }

        if (1 === preg_match('~'.$pattern.'~A', $this->wordWindow($text, $offset), $matches) && '' !== $matches[0]) {
            return [$matches[0], ''];
        }

        return [''];
    }

    /**
     * A start-string must not be followed by whitespace, and must not be
     * quoted: docutils drops a start-string sitting between a matching pair
     * of delimiters, such as the asterisk in "(*)".
     */
    private function opensContent(string $text, int $start, int $contentStart): bool
    {
        if ($contentStart < \strlen($text) && $this->isWhitespace($text[$contentStart])) {
            return false;
        }

        return !$this->isQuotedStart($text, $start, $contentStart);
    }

    private function isQuotedStart(string $text, int $start, int $contentStart): bool
    {
        if (0 === $start) {
            return false;
        }

        $closer = self::MATCHING[$text[$start - 1]] ?? null;

        if (null === $closer) {
            return false;
        }

        if ($contentStart >= \strlen($text)) {
            return true;
        }

        return $text[$contentStart] === $closer;
    }

    private function hasStartContext(string $text, int $offset): bool
    {
        if (0 === $offset) {
            return true;
        }

        $previous = $text[$offset - 1];

        return $this->isWhitespace($previous) || str_contains(self::START_BEFORE, $previous);
    }

    private function hasEndContext(string $text, int $offset): bool
    {
        if ($offset >= \strlen($text)) {
            return true;
        }

        return $this->isWhitespace($text[$offset]) || str_contains(self::END_AFTER, $text[$offset]);
    }

    /**
     * Removes backslash escapes and collapses whitespace runs to one space.
     */
    private function decode(string $raw): string
    {
        $decoded = '';
        $length = \strlen($raw);
        $offset = 0;

        while ($offset < $length) {
            $char = $raw[$offset];

            if ('\\' === $char) {
                [$piece, $offset] = $this->readEscape($raw, $offset);
                $decoded .= $piece;

                continue;
            }

            if ($this->isWhitespace($char)) {
                $decoded .= ' ';
                $offset += strspn($raw, self::WHITESPACE, $offset);

                continue;
            }

            $run = strcspn($raw, '\\'.self::WHITESPACE, $offset);
            $decoded .= substr($raw, $offset, $run);
            $offset += $run;
        }

        return $decoded;
    }

    /**
     * Reads the escape sequence starting at the backslash and returns the
     * text it contributes with the offset just after it. An escaped
     * whitespace run disappears entirely, which is how docutils joins a
     * word to the markup next to it.
     *
     * @return array{0: string, 1: int}
     */
    private function readEscape(string $raw, int $offset): array
    {
        $next = $offset + 1;

        if ($next >= \strlen($raw)) {
            return ['', $next];
        }

        if ($this->isWhitespace($raw[$next])) {
            return ['', $next + strspn($raw, self::WHITESPACE, $next)];
        }

        return [$raw[$next], $next + 1];
    }

    /**
     * The one normalization an inline literal accepts: a line terminator
     * plus the indentation around it becomes a single space.
     */
    private function joinLiteralLines(string $raw): string
    {
        return (string) preg_replace('~[ \t]*(?:\r\n|\n|\r)[ \t]*~', ' ', $raw);
    }

    /**
     * @param list<Node> $children
     */
    private function reportNestedMarkup(array $children, ByteSpan $span): void
    {
        foreach ($children as $child) {
            if (!$child instanceof InlineText) {
                $this->report(
                    'inline/nested-markup',
                    'Inline markup nested inside emphasis or strong; docutils reads it as plain text.',
                    $span,
                    ProblemSeverity::Info,
                );

                return;
            }
        }
    }

    private function isWhitespace(string $char): bool
    {
        return str_contains(self::WHITESPACE, $char);
    }

    private function report(string $code, string $message, ByteSpan $span, ProblemSeverity $severity = ProblemSeverity::Warning): void
    {
        $this->problems->add(new Problem($severity, $code, $message, $span));
    }
}
