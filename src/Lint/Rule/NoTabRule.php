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
 * Checks that no line contains a tab character.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class NoTabRule implements SourceRule
{
    public function code(): string
    {
        return 'lint/no-tab';
    }

    public function check(Document $document, Source $source, ProblemCollector $problems): void
    {
        foreach ($source->lines() as $line) {
            $position = strpos($source->slice($line->span), "\t");

            if (false === $position) {
                continue;
            }

            $problems->add(new Problem(
                ProblemSeverity::Warning,
                $this->code(),
                sprintf('Tab character on line %d; use spaces.', $line->index + 1),
                ByteSpan::of($line->span->start + $position, 1),
            ));
        }
    }
}
