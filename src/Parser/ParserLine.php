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

use Alto\Rst\Source\Line;

/**
 * The parser's view of one source line: the effective indentation column
 * and content offsets used for nesting and span decisions.
 *
 * A list item first line is re-based past its marker: the effective indent
 * becomes the column of the item text and the span start excludes the
 * marker, which belongs to the item, not to its first child block.
 *
 * The content substring is cached on first access: the scanner reads a
 * line's content several times across dispatch checks, and re-slicing the
 * source each time showed up in profiles.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ParserLine
{
    private ?string $content = null;

    public function __construct(
        public readonly Line $line,
        public readonly int $indentWidth,
        public readonly int $spanStart,
        public readonly int $contentStart,
        public readonly bool $blank,
    ) {
    }

    public static function fromLine(Line $line): self
    {
        return new self(
            $line,
            $line->indentWidth,
            $line->span->start,
            $line->contentSpan()->start,
            $line->isBlank(),
        );
    }

    public function end(): int
    {
        return $this->line->span->end();
    }

    /**
     * The line content from $bytes, without leading indentation. Every call
     * must pass the same source bytes; the first call fills the cache.
     */
    public function contentIn(string $bytes): string
    {
        return $this->content ??= substr($bytes, $this->contentStart, $this->line->span->end() - $this->contentStart);
    }
}
