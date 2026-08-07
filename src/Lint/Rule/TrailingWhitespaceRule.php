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

use Alto\Rst\Lint\SourceRule;
use Alto\Rst\Node\Document;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;

/**
 * Checks that no line ends with trailing whitespace.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class TrailingWhitespaceRule implements SourceRule
{
    public function code(): string
    {
        return 'lint/trailing-whitespace';
    }

    public function check(Document $document, Source $source, ProblemCollector $problems): void
    {
        foreach ($source->lines() as $line) {
            $content = $source->slice($line->span);
            $trimmed = rtrim($content, " \t\v\f");

            if (\strlen($trimmed) === \strlen($content)) {
                continue;
            }

            $problems->add(new Problem(
                ProblemSeverity::Info,
                $this->code(),
                sprintf('Trailing whitespace on line %d.', $line->index + 1),
                ByteSpan::between($line->span->start + \strlen($trimmed), $line->span->end()),
            ));
        }
    }
}
