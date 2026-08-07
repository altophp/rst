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

use Alto\Rst\Node\Document;
use Alto\Rst\Problem\ProblemCollector;

/**
 * A lint rule checking one structural constraint over a document tree.
 *
 * Rules never throw for findings: every finding is added to the collector
 * as a problem pointing at the offending node's span.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface DocumentRule
{
    /**
     * The stable kebab-case problem code, grouped by area, such as
     * "lint/transition-placement".
     */
    public function code(): string;

    public function check(Document $document, ProblemCollector $problems): void;
}
