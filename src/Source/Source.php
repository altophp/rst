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
 * The byte-accurate view of the input the parser consumes.
 *
 * The original bytes are kept verbatim; every line is a span over them. A
 * leading UTF-8 BOM is preserved in the bytes but excluded from the first
 * line's span, matching docutils, which skips it before parsing.
 *
 * Any byte sequence is a valid Source: this layer has no malformed input
 * concept.
 *
 * @implements \IteratorAggregate<int, Line>
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Source implements \IteratorAggregate
{
    private const string BOM = "\xEF\xBB\xBF";

    /**
     * @param list<Line> $lines
     */
    private function __construct(
        public string $bytes,
        private array $lines,
        private bool $bom,
    ) {
    }

    public static function fromString(string $bytes): self
    {
        $length = \strlen($bytes);
        $bom = str_starts_with($bytes, self::BOM);
        $offset = $bom ? \strlen(self::BOM) : 0;

        $lines = [];
        $index = 0;

        while ($offset < $length) {
            $contentEnd = $offset + strcspn($bytes, "\r\n", $offset);

            if ($contentEnd >= $length) {
                $terminator = '';
            } elseif ("\n" === $bytes[$contentEnd]) {
                $terminator = "\n";
            } elseif ($contentEnd + 1 < $length && "\n" === $bytes[$contentEnd + 1]) {
                $terminator = "\r\n";
            } else {
                $terminator = "\r";
            }

            $lines[] = Line::scan($bytes, $index, $offset, $contentEnd, $terminator);

            $offset = $contentEnd + \strlen($terminator);
            ++$index;
        }

        return new self($bytes, $lines, $bom);
    }

    public function hasBom(): bool
    {
        return $this->bom;
    }

    public function lineCount(): int
    {
        return \count($this->lines);
    }

    public function line(int $index): Line
    {
        if ($index < 0 || $index >= \count($this->lines)) {
            throw new InvalidArgumentException(\sprintf('Line index %d is out of range for %d lines.', $index, \count($this->lines)));
        }

        return $this->lines[$index];
    }

    /**
     * @return list<Line>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * @return \Traversable<int, Line>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->lines);
    }

    public function slice(ByteSpan $span): string
    {
        if ($span->end() > \strlen($this->bytes)) {
            throw new InvalidArgumentException(\sprintf('Span [%d, %d) exceeds the %d input bytes.', $span->start, $span->end(), \strlen($this->bytes)));
        }

        return substr($this->bytes, $span->start, $span->length);
    }
}
