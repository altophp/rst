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

use Alto\Rst\Source\Line;

/**
 * A forward cursor over a list of source lines already re-scanned to the
 * current container's effective start column, so indentWidth is always
 * relative to that container.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class MdLineCursor
{
    /**
     * @param list<Line> $lines
     */
    public function __construct(
        private readonly array $lines,
        private int $pos = 0,
    ) {}

    public function peek(int $offset = 0): ?Line
    {
        return $this->lines[$this->pos + $offset] ?? null;
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

    public function atEnd(): bool
    {
        return $this->pos >= \count($this->lines);
    }

    public function skipBlankLines(): void
    {
        while (null !== ($line = $this->peek()) && $line->isBlank()) {
            ++$this->pos;
        }
    }
}
