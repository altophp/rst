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

use Alto\Rst\Lint\DocumentRule;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\Section;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;

/**
 * Checks that every section carries body content beyond its title.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class EmptySectionRule implements DocumentRule
{
    public function code(): string
    {
        return 'lint/empty-section';
    }

    public function check(Document $document, ProblemCollector $problems): void
    {
        foreach ($document->descendants() as $node) {
            if (!$node instanceof Section || [] !== $node->body()) {
                continue;
            }

            $problems->add(new Problem(
                ProblemSeverity::Info,
                $this->code(),
                sprintf('Section "%s" has no content.', $node->title->text->text),
                $node->span(),
            ));
        }
    }
}
