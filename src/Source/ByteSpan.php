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

namespace Alto\Rst\Source;

use Alto\Rst\Exception\InvalidArgumentException;

/**
 * A half-open byte range [start, end) over the original input.
 *
 * Offsets always address original input bytes, never a normalized copy.
 * This contract is frozen: every node, problem, and edit references source
 * positions through it.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ByteSpan
{
    private function __construct(
        public int $start,
        public int $length,
    ) {
    }

    public static function of(int $start, int $length): self
    {
        if ($start < 0) {
            throw new InvalidArgumentException(sprintf('Span start must be >= 0, got %d.', $start));
        }

        if ($length < 0) {
            throw new InvalidArgumentException(sprintf('Span length must be >= 0, got %d.', $length));
        }

        return new self($start, $length);
    }

    public static function between(int $start, int $end): self
    {
        if ($end < $start) {
            throw new InvalidArgumentException(sprintf('Span end %d must be >= start %d.', $end, $start));
        }

        return self::of($start, $end - $start);
    }

    public function end(): int
    {
        return $this->start + $this->length;
    }

    public function isEmpty(): bool
    {
        return 0 === $this->length;
    }

    public function contains(int $offset): bool
    {
        return $offset >= $this->start && $offset < $this->end();
    }

    public function union(self $other): self
    {
        $start = min($this->start, $other->start);

        return self::between($start, max($this->end(), $other->end()));
    }
}
