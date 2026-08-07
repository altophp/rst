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

namespace Alto\Rst\Lint;

use Alto\Rst\Problem\ProblemCollector;

/**
 * A lint rule that needs both source bytes and document-wide reference
 * analysis.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface ContextRule
{
    /**
     * The stable kebab-case problem code.
     */
    public function code(): string;

    public function check(LintContext $context, ProblemCollector $problems): void;
}
