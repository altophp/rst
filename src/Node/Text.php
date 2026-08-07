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

namespace Alto\Rst\Node;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Source\ByteSpan;

/**
 * Raw inline placeholder: the literal source text and its span. The
 * inline pass replaces it with typed inline nodes in a later phase.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Text extends Node
{
    /**
     * @param list<ByteSpan> $sourceSegments Physical source slices for logical
     *                                       text assembled from non-contiguous
     *                                       regions, such as table cells
     */
    public function __construct(
        ByteSpan $span,
        public string $text,
        private array $sourceSegments = [],
    ) {
        if ([] !== $sourceSegments) {
            $parts = explode("\n", $text);

            if (\count($parts) !== \count($sourceSegments)) {
                throw new InvalidArgumentException('Text source segments must match its logical line count.');
            }

            $previousEnd = null;

            foreach ($sourceSegments as $index => $segment) {
                if ($segment->length !== \strlen($parts[$index])) {
                    throw new InvalidArgumentException('Text source segment lengths must match their logical lines.');
                }

                if ($segment->start < $span->start || $segment->end() > $span->end()) {
                    throw new InvalidArgumentException('Text source segments must stay within its span.');
                }

                if (null !== $previousEnd && $segment->start < $previousEnd) {
                    throw new InvalidArgumentException('Text source segments must be ordered and non-overlapping.');
                }

                $previousEnd = $segment->end();
            }
        }

        parent::__construct($span);
    }

    /**
     * @return list<ByteSpan>
     */
    public function sourceSegments(): array
    {
        return [] === $this->sourceSegments ? [$this->span()] : $this->sourceSegments;
    }
}
