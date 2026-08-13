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
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemReport;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Source\Source;

/**
 * Runs the enabled lint rules over a document and merges their findings
 * into a single report ordered by document position.
 *
 * Source rules run only when the original source is provided; without it
 * they are skipped.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Linter
{
    public function lint(
        Document $document,
        LintConfig $config,
        ?Source $source = null,
        ?ReferenceGraph $references = null,
        ?Profile $profile = null,
    ): ProblemReport {
        $collector = new ProblemCollector();
        $context = null;
        $effectiveConfig = $config;

        foreach ($profile?->extensions->lintRules() ?? [] as $rule) {
            $effectiveConfig = $effectiveConfig->withRule($rule);
        }

        foreach ($effectiveConfig->rules() as $rule) {
            if ($rule instanceof DocumentRule) {
                $rule->check($document, $collector);
            } elseif ($rule instanceof SourceRule && null !== $source) {
                $rule->check($document, $source, $collector);
            } elseif ($rule instanceof ContextRule && null !== $source) {
                $context ??= new LintContext(
                    $document,
                    $source,
                    $references ?? ReferenceGraph::fromDocument($document, $source),
                );
                $rule->check($context, $collector);
            }
        }

        $problems = $collector->report()->problems();

        usort(
            $problems,
            static fn(Problem $a, Problem $b): int => ($a->span->start ?? 0) <=> ($b->span->start ?? 0),
        );

        return new ProblemReport(...$problems);
    }
}
