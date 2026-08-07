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
 * A single line of the original input.
 *
 * The span covers the line content without its terminator. The terminator is
 * kept as written so edits can reproduce the input byte for byte. Indentation
 * is exposed both as leading whitespace bytes (for edits) and as expanded
 * columns (for the parser): tabs advance to the next multiple of 8 columns,
 * matching docutils.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Line
{
    /**
     * Bytes that make a line blank; terminator bytes never occur in content.
     */
    private const string BLANK_BYTES = " \t\v\f";

    private function __construct(
        public int $index,
        public ByteSpan $span,
        public string $terminator,
        public int $indentBytes,
        public int $indentWidth,
        private ByteSpan $contentSpan,
        private bool $blank,
    ) {
    }

    /**
     * Builds a line by scanning $bytes over the content range [start, end).
     */
    public static function scan(string $bytes, int $index, int $start, int $end, string $terminator): self
    {
        if (!\in_array($terminator, ['', "\n", "\r\n", "\r"], true)) {
            throw new InvalidArgumentException(\sprintf('Line terminator must be "", "\n", "\r\n", or "\r", got %s.', var_export($terminator, true)));
        }

        $span = ByteSpan::between($start, $end);

        if ($end + \strlen($terminator) > \strlen($bytes)) {
            throw new InvalidArgumentException(\sprintf('Line range [%d, %d) plus terminator exceeds the %d input bytes.', $start, $end, \strlen($bytes)));
        }

        $indentBytes = strspn($bytes, ' ', $start, $end - $start);
        $indentWidth = $indentBytes;

        if ($start + $indentBytes < $end && "\t" === $bytes[$start + $indentBytes]) {
            $indentBytes = 0;
            $indentWidth = 0;

            for ($offset = $start; $offset < $end; ++$offset) {
                $byte = $bytes[$offset];

                if (' ' === $byte) {
                    ++$indentWidth;
                } elseif ("\t" === $byte) {
                    $indentWidth += 8 - ($indentWidth % 8);
                } else {
                    break;
                }

                ++$indentBytes;
            }
        }

        $blank = $end === $start + strspn($bytes, self::BLANK_BYTES, $start, $end - $start);

        return new self(
            $index,
            $span,
            $terminator,
            $indentBytes,
            $indentWidth,
            ByteSpan::between($start + $indentBytes, $end),
            $blank,
        );
    }

    /**
     * The content span without its leading indentation.
     */
    public function contentSpan(): ByteSpan
    {
        return $this->contentSpan;
    }

    /**
     * The content span extended over the terminator bytes.
     */
    public function spanWithTerminator(): ByteSpan
    {
        return ByteSpan::of($this->span->start, $this->span->length + \strlen($this->terminator));
    }

    public function isBlank(): bool
    {
        return $this->blank;
    }
}
