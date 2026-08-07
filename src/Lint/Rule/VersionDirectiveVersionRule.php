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
 * Checks that every versionadded, versionchanged, and deprecated directive
 * carries a version number argument such as "7.1".
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class VersionDirectiveVersionRule implements DocumentRule
{
    private const array VERSION_DIRECTIVES = ['versionadded', 'versionchanged', 'deprecated'];

    private const string VERSION_PATTERN = '/^\d+\.\d+(\.\d+)*$/';

    public function code(): string
    {
        return 'lint/version-directive-version';
    }

    public function check(Document $document, ProblemCollector $problems): void
    {
        foreach ($document->descendants() as $node) {
            if (!$node instanceof Directive) {
                continue;
            }

            $name = strtolower($node->name);

            if (!\in_array($name, self::VERSION_DIRECTIVES, true)) {
                continue;
            }

            $version = $node->arguments[0] ?? null;

            if (null === $version) {
                $problems->add(new Problem(
                    ProblemSeverity::Warning,
                    $this->code(),
                    sprintf('The "%s" directive must have a version argument.', $name),
                    $node->span(),
                ));

                continue;
            }

            if (1 !== preg_match(self::VERSION_PATTERN, $version)) {
                $problems->add(new Problem(
                    ProblemSeverity::Warning,
                    $this->code(),
                    sprintf('Version "%s" of the "%s" directive is not a version number like "7.1".', $version, $name),
                    $node->span(),
                ));
            }
        }
    }
}
