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
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Reference\DefinitionKind;

/**
 * Reports unused external link definitions without flagging anchors,
 * implicit section targets, citations, footnotes, or substitutions.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class UnusedExternalLinkDefinitionRule implements ContextRule
{
    public function code(): string
    {
        return 'lint/unused-external-link-definition';
    }

    public function check(LintContext $context, ProblemCollector $problems): void
    {
        if (!$context->references->isCoverageComplete()) {
            return;
        }

        foreach ($context->references->unusedDefinitions() as $definition) {
            if (DefinitionKind::Hyperlink !== $definition->kind || null === $definition->destination) {
                continue;
            }

            $problems->add(new Problem(
                ProblemSeverity::Warning,
                $this->code(),
                \sprintf('External link definition "%s" is unused.', $definition->name),
                $definition->span,
            ));
        }
    }
}
