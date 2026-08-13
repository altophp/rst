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

/**
 * A forward cursor over parser lines. Nested regions parse through child
 * cursors built from a slice of the parent's lines.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class LineCursor
{
    /**
     * @param list<ParserLine> $lines
     */
    public function __construct(
        private readonly array $lines,
        private int $pos = 0,
    ) {}

    public function atEnd(): bool
    {
        return $this->pos >= \count($this->lines);
    }

    public function peek(int $offset = 0): ?ParserLine
    {
        return $this->lines[$this->pos + $offset] ?? null;
    }

    public function previous(): ?ParserLine
    {
        return $this->lines[$this->pos - 1] ?? null;
    }

    public function advance(): void
    {
        ++$this->pos;
    }

    public function position(): int
    {
        return $this->pos;
    }

    public function seek(int $pos): void
    {
        $this->pos = $pos;
    }

    public function skipBlankLines(): void
    {
        while (null !== ($line = $this->peek()) && $line->blank) {
            ++$this->pos;
        }
    }
}
