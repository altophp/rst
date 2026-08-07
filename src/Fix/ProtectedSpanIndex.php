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

namespace Alto\Rst\Fix;

use Alto\Rst\Node\Comment;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\LiteralBlock;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Table;
use Alto\Rst\Parser\ParseResult;
use Alto\Rst\Source\ByteSpan;

/**
 * Byte ranges where whitespace can be literal or parser recovery is ambiguous.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ProtectedSpanIndex
{
    /**
     * @param list<ByteSpan> $spans
     */
    private function __construct(
        private array $spans,
        private bool $protectAll = false,
    ) {
    }

    public static function fromParseResult(ParseResult $result): self
    {
        if ($result->problems()->hasProblems()) {
            return new self([], true);
        }

        $spans = [];

        foreach ($result->document()->descendants() as $node) {
            if (self::isProtectedNode($node)) {
                $spans[] = $node->span();
            }
        }

        return new self(self::merged($spans));
    }

    public function intersects(ByteSpan $span): bool
    {
        if ($this->protectAll) {
            return true;
        }

        foreach ($this->spans as $protected) {
            if ($protected->start >= $span->end()) {
                return false;
            }

            if ($span->start < $protected->end() && $span->end() > $protected->start) {
                return true;
            }
        }

        return false;
    }

    private static function isProtectedNode(Node $node): bool
    {
        return $node instanceof Comment
            || $node instanceof Directive
            || $node instanceof LiteralBlock
            || $node instanceof Table;
    }

    /**
     * @param list<ByteSpan> $spans
     *
     * @return list<ByteSpan>
     */
    private static function merged(array $spans): array
    {
        usort($spans, static fn (ByteSpan $left, ByteSpan $right): int => $left->start <=> $right->start);

        $merged = [];
        $current = null;

        foreach ($spans as $span) {
            if (null === $current) {
                $current = $span;

                continue;
            }

            if ($span->start > $current->end()) {
                $merged[] = $current;
                $current = $span;

                continue;
            }

            $current = $current->union($span);
        }

        if (null !== $current) {
            $merged[] = $current;
        }

        return $merged;
    }
}
