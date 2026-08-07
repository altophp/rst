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
use Alto\Rst\Node\HyperlinkTarget;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Reference\ReferenceName;

/**
 * Checks that no two named hyperlink targets share the same normalized
 * name (lowercased, whitespace runs collapsed to one space) document-wide.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DuplicateTargetRule implements DocumentRule
{
    public function code(): string
    {
        return 'lint/duplicate-target';
    }

    public function check(Document $document, ProblemCollector $problems): void
    {
        /** @var array<string, true> $seen */
        $seen = [];

        foreach ($document->descendants() as $node) {
            if (!$node instanceof HyperlinkTarget || $node->anonymous) {
                continue;
            }

            $normalized = ReferenceName::normalize($node->name);

            if (isset($seen[$normalized])) {
                $problems->add(new Problem(
                    ProblemSeverity::Warning,
                    $this->code(),
                    sprintf('Duplicate hyperlink target name "%s".', $normalized),
                    $node->span(),
                ));

                continue;
            }

            $seen[$normalized] = true;
        }
    }
}
