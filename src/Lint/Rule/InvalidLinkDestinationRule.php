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

use Alto\Rst\Lint\ContextRule;
use Alto\Rst\Lint\ExternalLinkDestination;
use Alto\Rst\Lint\LintContext;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;

/**
 * Checks URL syntax locally. It performs no DNS, HTTP, or filesystem I/O.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class InvalidLinkDestinationRule implements ContextRule
{
    public function code(): string
    {
        return 'lint/invalid-link-destination';
    }

    public function check(LintContext $context, ProblemCollector $problems): void
    {
        foreach (ExternalLinkDestination::fromGraph($context->references) as $destination) {
            if ($this->isValid($destination->url)) {
                continue;
            }

            $problems->add(new Problem(
                ProblemSeverity::Warning,
                $this->code(),
                \sprintf('Link destination is not a valid local URL: "%s".', $destination->url),
                $destination->span,
            ));
        }
    }

    private function isValid(string $url): bool
    {
        if (
            '' === $url
            || trim($url) !== $url
            || 1 === preg_match('/[\x00-\x20\x7f]/', $url)
            || 1 === preg_match('/%(?![0-9A-Fa-f]{2})/', $url)
        ) {
            return false;
        }

        $parts = parse_url($url);

        if (false === $parts) {
            return false;
        }

        if (str_starts_with($url, '//')) {
            return isset($parts['host']) && '' !== $parts['host'];
        }

        if (1 !== preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $url, $matches)) {
            return true;
        }

        $scheme = strtolower($matches[1]);

        if (\in_array($scheme, ['http', 'https', 'ftp'], true)) {
            return isset($parts['host']) && '' !== $parts['host'];
        }

        return isset($parts['path']) && '' !== $parts['path'];
    }
}
