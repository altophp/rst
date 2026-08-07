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

namespace Alto\Rst\Lint\Rule;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Lint\SourceRule;
use Alto\Rst\Node\BulletList;
use Alto\Rst\Node\Comment;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\EnumeratedList;
use Alto\Rst\Node\LiteralBlock;
use Alto\Rst\Node\Table;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;

/**
 * Checks that line indentation is a multiple of the configured size (the
 * Symfony convention is 4), skipping constructs whose indentation is
 * dictated by their markers or content: lists, tables, literal blocks,
 * comments, and code block bodies.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class IndentationRule implements SourceRule
{
    private const array CODE_BLOCK_DIRECTIVES = ['code-block', 'code', 'sourcecode'];

    public function __construct(
        private int $size = 4,
    ) {
        if ($size < 1) {
            throw new InvalidArgumentException(sprintf('Indentation size must be >= 1, got %d.', $size));
        }
    }

    public function code(): string
    {
        return 'lint/indentation';
    }

    public function check(Document $document, Source $source, ProblemCollector $problems): void
    {
        $skipped = self::skippedSpans($document);

        foreach ($source->lines() as $line) {
            if ($line->isBlank() || 0 === $line->indentWidth % $this->size) {
                continue;
            }

            if (self::isSkipped($line->contentSpan()->start, $skipped)) {
                continue;
            }

            $problems->add(new Problem(
                ProblemSeverity::Info,
                $this->code(),
                sprintf('Indentation of %d on line %d is not a multiple of %d.', $line->indentWidth, $line->index + 1, $this->size),
                ByteSpan::of($line->span->start, $line->indentBytes),
            ));
        }
    }

    /**
     * @return list<ByteSpan>
     */
    private static function skippedSpans(Document $document): array
    {
        $spans = [];

        foreach ($document->descendants() as $node) {
            if ($node instanceof BulletList
                || $node instanceof EnumeratedList
                || $node instanceof Table
                || $node instanceof LiteralBlock
                || $node instanceof Comment
            ) {
                $spans[] = $node->span();

                continue;
            }

            if ($node instanceof Directive
                && null !== $node->rawBody
                && \in_array(strtolower($node->name), self::CODE_BLOCK_DIRECTIVES, true)
            ) {
                $spans[] = $node->rawBody;
            }
        }

        return $spans;
    }

    /**
     * @param list<ByteSpan> $skipped
     */
    private static function isSkipped(int $offset, array $skipped): bool
    {
        foreach ($skipped as $span) {
            if ($span->contains($offset)) {
                return true;
            }
        }

        return false;
    }
}
