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
use Alto\Rst\Source\Source;

/**
 * A lint rule checking a constraint that needs the raw source bytes
 * alongside the document tree, such as whitespace or line conventions.
 *
 * Rules never throw for findings: every finding is added to the collector
 * as a problem pointing at the offending source span.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface SourceRule
{
    /**
     * The stable kebab-case problem code, grouped by area, such as
     * "lint/trailing-whitespace".
     */
    public function code(): string;

    public function check(Document $document, Source $source, ProblemCollector $problems): void;
}
