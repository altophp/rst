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
use Alto\Rst\Reference\ReferenceStatus;

/**
 * Reports references that failed local semantic resolution.
 *
 * Deferred Sphinx references are intentionally left to a project map.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class UnresolvedReferenceRule implements ContextRule
{
    public function code(): string
    {
        return 'lint/unresolved-reference';
    }

    public function check(LintContext $context, ProblemCollector $problems): void
    {
        foreach ($context->references->references() as $reference) {
            if (\in_array($reference->status, [ReferenceStatus::Resolved, ReferenceStatus::Deferred], true)) {
                continue;
            }

            $problems->add(new Problem(
                ProblemSeverity::Warning,
                $this->code(),
                \sprintf(
                    'Reference "%s" is %s.',
                    $reference->label,
                    $reference->status->value,
                ),
                $reference->span,
            ));
        }
    }
}
