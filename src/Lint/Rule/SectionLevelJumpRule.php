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
use Alto\Rst\Node\ContainerNode;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Section;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;

/**
 * Checks that a section's level is at most one deeper than its enclosing
 * section context (the document body counts as level 0).
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SectionLevelJumpRule implements DocumentRule
{
    public function code(): string
    {
        return 'lint/section-level-jump';
    }

    public function check(Document $document, ProblemCollector $problems): void
    {
        $this->checkChildren($document->children(), 0, $problems);
    }

    /**
     * @param list<Node> $children
     */
    private function checkChildren(array $children, int $contextLevel, ProblemCollector $problems): void
    {
        foreach ($children as $child) {
            if ($child instanceof Section) {
                if ($child->level > $contextLevel + 1) {
                    $problems->add(new Problem(
                        ProblemSeverity::Warning,
                        $this->code(),
                        sprintf('Section level jumps from %d to %d.', $contextLevel, $child->level),
                        $child->span(),
                    ));
                }

                $this->checkChildren($child->body(), $child->level, $problems);

                continue;
            }

            if ($child instanceof ContainerNode) {
                $this->checkChildren($child->children(), $contextLevel, $problems);
            }
        }
    }
}
