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
use Alto\Rst\Node\Transition;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;

/**
 * Checks that no transition opens or closes the document body and that no
 * two transitions are adjacent in any container.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class TransitionPlacementRule implements DocumentRule
{
    public function code(): string
    {
        return 'lint/transition-placement';
    }

    public function check(Document $document, ProblemCollector $problems): void
    {
        $children = $document->children();
        $first = $children[0] ?? null;
        $last = $children[count($children) - 1] ?? null;

        if ($first instanceof Transition) {
            $problems->add(new Problem(
                ProblemSeverity::Warning,
                $this->code(),
                'Document may not begin with a transition.',
                $first->span(),
            ));
        }

        if ($last instanceof Transition && $last !== $first) {
            $problems->add(new Problem(
                ProblemSeverity::Warning,
                $this->code(),
                'Document may not end with a transition.',
                $last->span(),
            ));
        }

        $this->checkAdjacent($document, $problems);
    }

    private function checkAdjacent(ContainerNode $container, ProblemCollector $problems): void
    {
        $previousWasTransition = false;

        foreach ($container->children() as $child) {
            if ($child instanceof Transition) {
                if ($previousWasTransition) {
                    $problems->add(new Problem(
                        ProblemSeverity::Warning,
                        $this->code(),
                        'At least one body element must separate transitions.',
                        $child->span(),
                    ));
                }

                $previousWasTransition = true;

                continue;
            }

            $previousWasTransition = false;

            if ($child instanceof ContainerNode) {
                $this->checkAdjacent($child, $problems);
            }
        }
    }
}
