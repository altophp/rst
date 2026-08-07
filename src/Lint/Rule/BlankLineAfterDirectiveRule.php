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
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\Document;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Source\Source;

/**
 * Checks that a blank line separates a directive's head (marker line and
 * option block) from its content.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class BlankLineAfterDirectiveRule implements SourceRule
{
    private const string OPTION_PATTERN = '/^:[^:]+:(\s|$)/';

    public function code(): string
    {
        return 'lint/blank-line-after-directive';
    }

    public function check(Document $document, Source $source, ProblemCollector $problems): void
    {
        foreach ($document->descendants() as $node) {
            if (!$node instanceof Directive || null === $node->rawBody) {
                continue;
            }

            $this->checkDirective($node, $source, $problems);
        }
    }

    private function checkDirective(Directive $directive, Source $source, ProblemCollector $problems): void
    {
        $index = self::lineIndexAt($source, $directive->span()->start);

        if (null === $index) {
            return;
        }

        $end = $directive->span()->end();

        for ($i = $index + 1; $i < $source->lineCount(); ++$i) {
            $line = $source->line($i);

            if ($line->span->start >= $end) {
                return;
            }

            if ($line->isBlank()) {
                return;
            }

            if (1 === preg_match(self::OPTION_PATTERN, $source->slice($line->contentSpan()))) {
                continue;
            }

            $problems->add(new Problem(
                ProblemSeverity::Info,
                $this->code(),
                sprintf('Add a blank line after the "%s" directive before its content.', $directive->name),
                $line->span,
            ));

            return;
        }
    }

    private static function lineIndexAt(Source $source, int $offset): ?int
    {
        foreach ($source->lines() as $line) {
            if ($offset >= $line->span->start && $offset < $line->spanWithTerminator()->end()) {
                return $line->index;
            }
        }

        return null;
    }
}
