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
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\Document;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;

/**
 * Checks that no directive from the forbidden set is used; the default set
 * mirrors the Symfony documentation standards, which forbid "index" and
 * "caution" (replaced by "warning" or "danger").
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ForbiddenDirectiveRule implements DocumentRule
{
    /**
     * @var array<string, list<string>>
     */
    private array $forbidden;

    /**
     * @param array<string, list<string>> $forbidden forbidden directive name
     *                                               mapped to its suggested
     *                                               replacements, possibly none
     */
    public function __construct(
        array $forbidden = ['index' => [], 'caution' => ['warning', 'danger']],
    ) {
        $normalized = [];

        foreach ($forbidden as $name => $replacements) {
            $normalized[strtolower($name)] = $replacements;
        }

        $this->forbidden = $normalized;
    }

    public function code(): string
    {
        return 'lint/forbidden-directive';
    }

    public function check(Document $document, ProblemCollector $problems): void
    {
        foreach ($document->descendants() as $node) {
            if (!$node instanceof Directive) {
                continue;
            }

            $name = strtolower($node->name);

            if (!\array_key_exists($name, $this->forbidden)) {
                continue;
            }

            $replacements = $this->forbidden[$name];

            $message = [] === $replacements
                ? sprintf('Directive "%s" is forbidden.', $name)
                : sprintf(
                    'Directive "%s" is forbidden; use %s instead.',
                    $name,
                    implode(' or ', array_map(static fn(string $replacement): string => sprintf('"%s"', $replacement), $replacements)),
                );

            $problems->add(new Problem(
                ProblemSeverity::Warning,
                $this->code(),
                $message,
                $node->span(),
            ));
        }
    }
}
