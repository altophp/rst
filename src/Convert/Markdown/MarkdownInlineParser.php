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
 * Parses a contiguous slice of Markdown source into inline nodes.
 *
 * A single left-to-right scan resolves backslash escapes, code spans,
 * autolinks, raw inline HTML, and links/images eagerly (these have
 * precedence over emphasis in CommonMark). Emphasis and strong emphasis are
 * resolved afterward with a delimiter-stack pass restricted to "*" and "_",
 * modelled on the CommonMark reference algorithm without the "multiple of
 * three" rule, which is a deliberate simplification for this small subset.
 *
 * Never throws: any construct that fails to parse degrades to literal text.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MarkdownInlineParser
{
    private const string ESCAPABLE = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    private const string URI_AUTOLINK = '/^<([A-Za-z][A-Za-z0-9+.-]{1,31}:[^\s<>]*)>/';

    private const string EMAIL_AUTOLINK = '/^<([A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+)>/';

    private const string HTML_TAG = '/^(<[A-Za-z][A-Za-z0-9-]*(?:\s+[A-Za-z_:][A-Za-z0-9_.:-]*(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*\s*\/?>|<\/[A-Za-z][A-Za-z0-9-]*\s*>|<!--(?:(?!-->).)*-->|<\?.*?\?>|<![A-Za-z]+\s+[^>]*>|<!\[CDATA\[.*?\]\]>)/s';

    /**
     * @param array<string, MdLinkReferenceDefinition> $definitions normalized label => definition
     */
    public function __construct(
        private array $definitions = [],
    ) {}

    public static function normalizeLabel(string $label): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($label));

        return strtolower($collapsed ?? trim($label));
    }

    /**
     * @return list<MdNode>
     */
    public function parse(string $text, int $baseOffset): array
    {
        $head = $this->tokenize($text, $baseOffset);
        $head = $this->processEmphasis($head);

        return $this->flattenList($head);
    }

    private function tokenize(string $text, int $baseOffset): ?MarkdownInlineItem
    {
        $length = \strlen($text);
        $pos = 0;
        $textStart = 0;
        $head = null;
        $tail = null;

        while ($pos < $length) {
            $char = $text[$pos];
            $special = null;

            if ('\\' === $char) {
                $result = $this->tryBackslashEscape($text, $pos, $baseOffset);
                $special = $result;
            } elseif ('`' === $char) {
                $result = $this->tryCodeSpan($text, $pos, $baseOffset);
                if (null !== $result) {
                    $special = $result;
                } else {
                    $runLength = $this->runLength($text, $pos, '`');
                    $special = [
                        'item' => MarkdownInlineItem::ofText(substr($text, $pos, $runLength), $baseOffset + $pos, $baseOffset + $pos + $runLength),
                        'next' => $pos + $runLength,
                    ];
                }
            } elseif ('<' === $char) {
                $special = $this->tryAutolinkOrHtml($text, $pos, $baseOffset);
            } elseif ('!' === $char && $pos + 1 < $length && '[' === $text[$pos + 1]) {
                $special = $this->tryLinkOrImage($text, $pos, $baseOffset, true);
            } elseif ('[' === $char) {
                $special = $this->tryLinkOrImage($text, $pos, $baseOffset, false);
            } elseif ('*' === $char || '_' === $char) {
                $runLength = $this->runLength($text, $pos, $char);
                [$canOpen, $canClose] = $this->flanking($text, $pos, $runLength, $char);
                $special = [
                    'item' => MarkdownInlineItem::ofDelimiter($char, $runLength, $canOpen, $canClose, $baseOffset + $pos, $baseOffset + $pos + $runLength),
                    'next' => $pos + $runLength,
                ];
            }

            if (null === $special) {
                ++$pos;

                continue;
            }

            if ($pos > $textStart) {
                $raw = substr($text, $textStart, $pos - $textStart);
                [$head, $tail] = $this->pushItem($head, $tail, MarkdownInlineItem::ofText($raw, $baseOffset + $textStart, $baseOffset + $pos));
            }

            [$head, $tail] = $this->pushItem($head, $tail, $special['item']);
            $pos = $special['next'];
            $textStart = $pos;
        }

        if ($pos > $textStart) {
            $raw = substr($text, $textStart, $pos - $textStart);
            [$head] = $this->pushItem($head, $tail, MarkdownInlineItem::ofText($raw, $baseOffset + $textStart, $baseOffset + $pos));
        }

        return $head;
    }

    /**
     * @return array{0: ?MarkdownInlineItem, 1: ?MarkdownInlineItem}
     */
    private function pushItem(?MarkdownInlineItem $head, ?MarkdownInlineItem $tail, MarkdownInlineItem $item): array
    {
        if (null === $head || null === $tail) {
            return [$item, $item];
        }

        $item->prev = $tail;
        $tail->next = $item;

        return [$head, $item];
    }

    private function processEmphasis(?MarkdownInlineItem $head): ?MarkdownInlineItem
    {
        /** @var list<MarkdownInlineItem> $stack */
        $stack = [];
        $current = $head;

        while (null !== $current) {
            $next = $current->next;

            if (null !== $current->delimChar) {
                if ($current->canClose) {
                    while ($current->delimCount > 0) {
                        $openerIndex = $this->findOpenerIndex($stack, $current->delimChar);

                        if (null === $openerIndex) {
                            break;
                        }

                        $opener = $stack[$openerIndex];
                        $n = min(2, $opener->delimCount, $current->delimCount);
                        $head = $this->wrap($opener, $current, $n, $head);
                        array_splice($stack, $openerIndex);

                        if (0 === $opener->delimCount) {
                            $head = $this->removeItem($opener, $head);
                        } else {
                            $stack[] = $opener;
                        }
                    }
                }

                if ($current->delimCount > 0 && $current->canOpen) {
                    $stack[] = $current;
                }

                if (0 === $current->delimCount) {
                    $head = $this->removeItem($current, $head);
                }
            }

            $current = $next;
        }

        return $head;
    }

    /**
     * @param list<MarkdownInlineItem> $stack
     */
    private function findOpenerIndex(array $stack, ?string $char): ?int
    {
        for ($i = \count($stack) - 1; $i >= 0; --$i) {
            if ($stack[$i]->delimChar === $char) {
                return $i;
            }
        }

        return null;
    }

    private function wrap(MarkdownInlineItem $opener, MarkdownInlineItem $closer, int $n, ?MarkdownInlineItem $head): ?MarkdownInlineItem
    {
        $children = [];
        $cursor = $opener->next;

        while (null !== $cursor && $cursor !== $closer) {
            $this->appendNode($children, $this->itemToNode($cursor));
            $cursor = $cursor->next;
        }

        $openerConsumedStart = $opener->end - $n;
        $closerConsumedEnd = $closer->start + $n;
        $marker = str_repeat((string) $opener->delimChar, $n);
        $span = ByteSpan::between($openerConsumedStart, $closerConsumedEnd);
        $node = 2 === $n
            ? new MdStrong($span, $marker, $children)
            : new MdEmphasis($span, $marker, $children);

        $wrapped = MarkdownInlineItem::ofNode($node, $openerConsumedStart, $closerConsumedEnd);
        $wrapped->prev = $opener;
        $wrapped->next = $closer;
        $opener->next = $wrapped;
        $closer->prev = $wrapped;

        $opener->end = $openerConsumedStart;
        $opener->delimCount -= $n;
        $opener->text = substr($opener->text, 0, \strlen($opener->text) - $n);

        $closer->start = $closerConsumedEnd;
        $closer->delimCount -= $n;
        $closer->text = substr($closer->text, $n);

        return $head;
    }

    private function removeItem(MarkdownInlineItem $item, ?MarkdownInlineItem $head): ?MarkdownInlineItem
    {
        if ($item === $head) {
            $head = $item->next;
        }

        if (null !== $item->prev) {
            $item->prev->next = $item->next;
        }

        if (null !== $item->next) {
            $item->next->prev = $item->prev;
        }

        return $head;
    }

    /**
     * @return list<MdNode>
     */
    private function flattenList(?MarkdownInlineItem $head): array
    {
        $result = [];
        $current = $head;

        while (null !== $current) {
            $this->appendNode($result, $this->itemToNode($current));
            $current = $current->next;
        }

        return $result;
    }

    /**
     * @param list<MdNode> $list
     */
    private function appendNode(array &$list, MdNode $node): void
    {
        if ([] !== $list && $node instanceof MdText) {
            $lastIndex = \count($list) - 1;
            $last = $list[$lastIndex];

            if ($last instanceof MdText) {
                $list[$lastIndex] = new MdText($last->span()->union($node->span()), $last->text . $node->text);

                return;
            }
        }

        $list[] = $node;
    }

    private function itemToNode(MarkdownInlineItem $item): MdNode
    {
        if (null !== $item->node) {
            return $item->node;
        }

        return new MdText(ByteSpan::between($item->start, $item->end), $this->normalizeWhitespace($item->text));
    }

    /**
     * Collapses every run of whitespace, including a line ending plus the
     * indentation that follows it, to a single space -- the same inline
     * whitespace normalization the rest of this engine applies.
     */
    private function normalizeWhitespace(string $text): string
    {
        return preg_replace('/[ \t\r\n\f\v]+/', ' ', $text) ?? $text;
    }

    /**
     * @return array{item: MarkdownInlineItem, next: int}
     */
    private function tryBackslashEscape(string $text, int $pos, int $baseOffset): array
    {
        $length = \strlen($text);

        if ($pos + 1 < $length && str_contains(self::ESCAPABLE, $text[$pos + 1])) {
            return [
                'item' => MarkdownInlineItem::ofText($text[$pos + 1], $baseOffset + $pos, $baseOffset + $pos + 2),
                'next' => $pos + 2,
            ];
        }

        return [
            'item' => MarkdownInlineItem::ofText('\\', $baseOffset + $pos, $baseOffset + $pos + 1),
            'next' => $pos + 1,
        ];
    }

    /**
     * @return array{item: MarkdownInlineItem, next: int}|null
     */
    private function tryCodeSpan(string $text, int $pos, int $baseOffset): ?array
    {
        $length = \strlen($text);
        $openLength = $this->runLength($text, $pos, '`');
        $searchPos = $pos + $openLength;

        while ($searchPos < $length) {
            $tickPos = strpos($text, '`', $searchPos);

            if (false === $tickPos) {
                return null;
            }

            $runLen = $this->runLength($text, $tickPos, '`');

            if ($runLen === $openLength) {
                $content = $this->normalizeWhitespace(substr($text, $pos + $openLength, $tickPos - $pos - $openLength));
                $end = $tickPos + $runLen;
                $node = new MdCode(ByteSpan::between($baseOffset + $pos, $baseOffset + $end), $this->trimCodeSpanContent($content), $openLength);

                return [
                    'item' => MarkdownInlineItem::ofNode($node, $baseOffset + $pos, $baseOffset + $end),
                    'next' => $end,
                ];
            }

            $searchPos = $tickPos + $runLen;
        }

        return null;
    }

    private function trimCodeSpanContent(string $content): string
    {
        $allSpaces = '' === trim($content, ' ');

        if (!$allSpaces && str_starts_with($content, ' ') && str_ends_with($content, ' ')) {
            return substr($content, 1, -1);
        }

        return $content;
    }

    /**
     * @return array{item: MarkdownInlineItem, next: int}|null
     */
    private function tryAutolinkOrHtml(string $text, int $pos, int $baseOffset): ?array
    {
        $slice = substr($text, $pos);

        if (1 === preg_match(self::URI_AUTOLINK, $slice, $m)) {
            $uri = $m[1];
            $end = $pos + \strlen($m[0]);
            $textNode = new MdText(ByteSpan::between($baseOffset + $pos + 1, $baseOffset + $end - 1), $uri);
            $link = new MdLink(ByteSpan::between($baseOffset + $pos, $baseOffset + $end), [$textNode], $uri, null, MdLinkStyle::Inline);

            return ['item' => MarkdownInlineItem::ofNode($link, $baseOffset + $pos, $baseOffset + $end), 'next' => $end];
        }

        if (1 === preg_match(self::EMAIL_AUTOLINK, $slice, $m)) {
            $email = $m[1];
            $end = $pos + \strlen($m[0]);
            $textNode = new MdText(ByteSpan::between($baseOffset + $pos + 1, $baseOffset + $end - 1), $email);
            $link = new MdLink(ByteSpan::between($baseOffset + $pos, $baseOffset + $end), [$textNode], 'mailto:' . $email, null, MdLinkStyle::Inline);

            return ['item' => MarkdownInlineItem::ofNode($link, $baseOffset + $pos, $baseOffset + $end), 'next' => $end];
        }

        if (1 === preg_match(self::HTML_TAG, $slice, $m)) {
            $end = $pos + \strlen($m[0]);
            $html = new MdInlineHtml(ByteSpan::between($baseOffset + $pos, $baseOffset + $end), $m[0]);

            return ['item' => MarkdownInlineItem::ofNode($html, $baseOffset + $pos, $baseOffset + $end), 'next' => $end];
        }

        return null;
    }

    /**
     * @return array{item: MarkdownInlineItem, next: int}|null
     */
    private function tryLinkOrImage(string $text, int $pos, int $baseOffset, bool $image): ?array
    {
        $openPos = $image ? $pos + 1 : $pos;
        $closeBracket = $this->findClosingBracket($text, $openPos + 1, true);

        if (null === $closeBracket) {
            return null;
        }

        $labelText = substr($text, $openPos + 1, $closeBracket - $openPos - 1);
        $afterBracket = $closeBracket + 1;
        $length = \strlen($text);

        if ($afterBracket < $length && '(' === $text[$afterBracket]) {
            $inline = $this->tryInlineDestination($text, $afterBracket);

            if (null !== $inline) {
                $children = $this->parse($labelText, $baseOffset + $openPos + 1);
                $span = ByteSpan::between($baseOffset + $pos, $baseOffset + $inline['next']);
                $node = $image
                    ? new MdImage($span, $children, $inline['url'], $inline['title'], MdLinkStyle::Inline)
                    : new MdLink($span, $children, $inline['url'], $inline['title'], MdLinkStyle::Inline);

                return ['item' => MarkdownInlineItem::ofNode($node, $span->start, $span->end()), 'next' => $inline['next']];
            }
        }

        $refLabel = $labelText;
        $refEnd = $afterBracket;

        if ($afterBracket < $length && '[' === $text[$afterBracket]) {
            $secondClose = $this->findClosingBracket($text, $afterBracket + 1, false);

            if (null !== $secondClose) {
                $explicitLabel = substr($text, $afterBracket + 1, $secondClose - $afterBracket - 1);

                if ('' !== $explicitLabel) {
                    $refLabel = $explicitLabel;
                }

                $refEnd = $secondClose + 1;
            }
        }

        $definition = $this->definitions[self::normalizeLabel($refLabel)] ?? null;

        if (null === $definition || '' === trim($refLabel)) {
            return null;
        }

        $children = $this->parse($labelText, $baseOffset + $openPos + 1);
        $span = ByteSpan::between($baseOffset + $pos, $baseOffset + $refEnd);
        $node = $image
            ? new MdImage($span, $children, $definition->url, $definition->title, MdLinkStyle::Reference, $refLabel)
            : new MdLink($span, $children, $definition->url, $definition->title, MdLinkStyle::Reference, $refLabel);

        return ['item' => MarkdownInlineItem::ofNode($node, $span->start, $span->end()), 'next' => $refEnd];
    }

    private function findClosingBracket(string $text, int $start, bool $allowNesting): ?int
    {
        $length = \strlen($text);
        $depth = 0;

        for ($i = $start; $i < $length; ++$i) {
            $char = $text[$i];

            if ('\\' === $char) {
                ++$i;

                continue;
            }

            if ('`' === $char) {
                $runLen = $this->runLength($text, $i, '`');
                $i = $this->skipCodeSpanRun($text, $i, $runLen);

                continue;
            }

            if ('[' === $char && $allowNesting) {
                ++$depth;

                continue;
            }

            if (']' === $char) {
                if ($depth > 0) {
                    --$depth;

                    continue;
                }

                return $i;
            }
        }

        return null;
    }

    private function skipCodeSpanRun(string $text, int $backtickPos, int $runLength): int
    {
        $length = \strlen($text);
        $searchPos = $backtickPos + $runLength;

        while ($searchPos < $length) {
            $tickPos = strpos($text, '`', $searchPos);

            if (false === $tickPos) {
                return $backtickPos + $runLength - 1;
            }

            $closeRun = $this->runLength($text, $tickPos, '`');

            if ($closeRun === $runLength) {
                return $tickPos + $closeRun - 1;
            }

            $searchPos = $tickPos + $closeRun;
        }

        return $backtickPos + $runLength - 1;
    }

    /**
     * @return array{url: string, title: ?string, next: int}|null
     */
    private function tryInlineDestination(string $text, int $openParenPos): ?array
    {
        $length = \strlen($text);
        $pos = $this->skipSpaces($text, $openParenPos + 1);

        if ($pos < $length && '<' === $text[$pos]) {
            $end = strpos($text, '>', $pos + 1);

            if (false === $end) {
                return null;
            }

            $url = self::resolveBackslashEscapes(substr($text, $pos + 1, $end - $pos - 1));
            $pos = $end + 1;
        } else {
            $start = $pos;
            $depth = 0;

            while ($pos < $length) {
                $char = $text[$pos];

                if ('\\' === $char) {
                    $pos += 2;

                    continue;
                }

                if ('(' === $char) {
                    ++$depth;
                    ++$pos;

                    continue;
                }

                if (')' === $char) {
                    if (0 === $depth) {
                        break;
                    }

                    --$depth;
                    ++$pos;

                    continue;
                }

                if (' ' === $char || "\t" === $char) {
                    break;
                }

                ++$pos;
            }

            $url = self::resolveBackslashEscapes(substr($text, $start, $pos - $start));
        }

        $pos = $this->skipSpaces($text, $pos);
        $title = null;

        if ($pos < $length && ('"' === $text[$pos] || "'" === $text[$pos] || '(' === $text[$pos])) {
            $quote = '(' === $text[$pos] ? ')' : $text[$pos];
            $end = $pos + 1;

            while ($end < $length) {
                if ('\\' === $text[$end]) {
                    $end += 2;

                    continue;
                }

                if ($text[$end] === $quote) {
                    break;
                }

                ++$end;
            }

            if ($end >= $length) {
                return null;
            }

            $title = self::resolveBackslashEscapes(substr($text, $pos + 1, $end - $pos - 1));
            $pos = $this->skipSpaces($text, $end + 1);
        }

        if ($pos >= $length || ')' !== $text[$pos]) {
            return null;
        }

        return ['url' => $url, 'title' => $title, 'next' => $pos + 1];
    }

    private function skipSpaces(string $text, int $pos): int
    {
        $length = \strlen($text);

        while ($pos < $length && (' ' === $text[$pos] || "\t" === $text[$pos])) {
            ++$pos;
        }

        return $pos;
    }

    public static function resolveBackslashEscapes(string $text): string
    {
        $result = '';
        $length = \strlen($text);

        for ($i = 0; $i < $length; ++$i) {
            if ('\\' === $text[$i] && $i + 1 < $length && str_contains(self::ESCAPABLE, $text[$i + 1])) {
                $result .= $text[$i + 1];
                ++$i;

                continue;
            }

            $result .= $text[$i];
        }

        return $result;
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function flanking(string $text, int $pos, int $runLength, string $char): array
    {
        $before = $pos > 0 ? $text[$pos - 1] : ' ';
        $afterPos = $pos + $runLength;
        $after = $afterPos < \strlen($text) ? $text[$afterPos] : ' ';

        $beforeIsWhitespace = $this->isWhitespace($before);
        $afterIsWhitespace = $this->isWhitespace($after);
        $beforeIsPunct = self::isAsciiPunctuation($before);
        $afterIsPunct = self::isAsciiPunctuation($after);

        $leftFlanking = !$afterIsWhitespace && (!$afterIsPunct || $beforeIsWhitespace || $beforeIsPunct);
        $rightFlanking = !$beforeIsWhitespace && (!$beforeIsPunct || $afterIsWhitespace || $afterIsPunct);

        if ('_' === $char) {
            $canOpen = $leftFlanking && (!$rightFlanking || $beforeIsPunct);
            $canClose = $rightFlanking && (!$leftFlanking || $afterIsPunct);
        } else {
            $canOpen = $leftFlanking;
            $canClose = $rightFlanking;
        }

        return [$canOpen, $canClose];
    }

    private function isWhitespace(string $char): bool
    {
        return match ($char) {
            ' ', "\t", "\n", "\r", "\f", "\v" => true,
            default => false,
        };
    }

    private static function isAsciiPunctuation(string $char): bool
    {
        return '' !== $char && str_contains(self::ESCAPABLE, $char);
    }

    private function runLength(string $text, int $pos, string $char): int
    {
        $length = \strlen($text);
        $end = $pos;

        while ($end < $length && $text[$end] === $char) {
            ++$end;
        }

        return $end - $pos;
    }
}
