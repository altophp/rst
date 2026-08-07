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
use Alto\Rst\Lint\ExternalLinkDestination;
use Alto\Rst\Lint\LintContext;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Render\HtmlPolicy;

/**
 * Applies the renderer's safe URL-scheme policy during lint.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ForbiddenLinkDestinationRule implements ContextRule
{
    private HtmlPolicy $policy;

    public function __construct(?HtmlPolicy $policy = null)
    {
        $this->policy = $policy ?? HtmlPolicy::safe();
    }

    public function code(): string
    {
        return 'lint/forbidden-link-destination';
    }

    public function check(LintContext $context, ProblemCollector $problems): void
    {
        foreach (ExternalLinkDestination::fromGraph($context->references) as $destination) {
            if ($this->policy->isUrlAllowed($destination->url)) {
                continue;
            }

            $problems->add(new Problem(
                ProblemSeverity::Warning,
                $this->code(),
                \sprintf('Link destination uses a forbidden URL scheme: "%s".', $destination->url),
                $destination->span,
            ));
        }
    }
}
