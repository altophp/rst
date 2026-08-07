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

use Alto\Rst\Lint\ContextRule;
use Alto\Rst\Lint\LintContext;
use Alto\Rst\Node\HyperlinkTarget;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Reference\DefinitionKind;
use Alto\Rst\Source\Line;

/**
 * Requires a blank separator after an explicit internal anchor.
 *
 * External link definitions are excluded because consecutive definitions
 * are valid and conventional.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class BlankLineAfterAnchorRule implements ContextRule
{
    public function code(): string
    {
        return 'lint/blank-line-after-anchor';
    }

    public function check(LintContext $context, ProblemCollector $problems): void
    {
        foreach ($context->references->definitions(DefinitionKind::Hyperlink) as $definition) {
            if (
                !$definition->node instanceof HyperlinkTarget
                || '' !== $definition->node->target
                || null === ($line = $this->lineAt($context, $definition->span->start))
                || $line->index + 1 >= $context->source->lineCount()
            ) {
                continue;
            }

            $next = $context->source->line($line->index + 1);

            if ($next->isBlank()) {
                continue;
            }

            $problems->add(new Problem(
                ProblemSeverity::Info,
                $this->code(),
                \sprintf('Add a blank line after the "%s" anchor.', $definition->name),
                $next->span,
            ));
        }
    }

    private function lineAt(LintContext $context, int $offset): ?Line
    {
        foreach ($context->source->lines() as $line) {
            if ($offset >= $line->span->start && $offset < $line->spanWithTerminator()->end()) {
                return $line;
            }
        }

        return null;
    }
}
