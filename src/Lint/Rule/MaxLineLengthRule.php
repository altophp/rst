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
use Alto\Rst\Node\Document;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Source\Source;

/**
 * Checks that no line is longer than the configured character limit; the
 * Symfony documentation wraps at 80.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MaxLineLengthRule implements SourceRule
{
    public function __construct(
        private int $limit = 80,
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('Line length limit must be >= 1, got %d.', $limit));
        }
    }

    public function code(): string
    {
        return 'lint/max-line-length';
    }

    public function check(Document $document, Source $source, ProblemCollector $problems): void
    {
        foreach ($source->lines() as $line) {
            $length = self::characterLength($source->slice($line->span));

            if ($length <= $this->limit) {
                continue;
            }

            $problems->add(new Problem(
                ProblemSeverity::Info,
                $this->code(),
                sprintf('Line %d exceeds %d characters (found %d).', $line->index + 1, $this->limit, $length),
                $line->span,
            ));
        }
    }

    /**
     * UTF-8 aware character count; invalid UTF-8 degrades to a byte count.
     */
    private static function characterLength(string $content): int
    {
        $count = preg_match_all('/./su', $content);

        return false === $count ? \strlen($content) : $count;
    }
}
