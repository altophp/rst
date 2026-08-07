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

/**
 * One node of the mutable doubly linked list the inline parser builds
 * while scanning, and then reduces in place while resolving emphasis and
 * strong emphasis delimiter runs.
 *
 * Exactly one of $node or $delimChar is set for a resolved-node or
 * delimiter-run item; both are null for a plain text run, in which case
 * $text carries the literal content.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class MarkdownInlineItem
{
    public ?self $prev = null;
    public ?self $next = null;

    private function __construct(
        public string $text,
        public ?MdNode $node,
        public ?string $delimChar,
        public int $delimCount,
        public bool $canOpen,
        public bool $canClose,
        public int $start,
        public int $end,
    ) {
    }

    public static function ofText(string $text, int $start, int $end): self
    {
        return new self($text, null, null, 0, false, false, $start, $end);
    }

    public static function ofNode(MdNode $node, int $start, int $end): self
    {
        return new self('', $node, null, 0, false, false, $start, $end);
    }

    public static function ofDelimiter(string $char, int $count, bool $canOpen, bool $canClose, int $start, int $end): self
    {
        return new self(str_repeat($char, $count), null, $char, $count, $canOpen, $canClose, $start, $end);
    }
}
