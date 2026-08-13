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

namespace Alto\Rst\Reference;

/**
 * Docutils simple-name normalization with the project's ASCII-only policy.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ReferenceName
{
    private function __construct() {}

    public static function normalize(string $name): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
    }

    public static function id(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9._:-]+/', '-', strtolower($name)), '-');
    }
}
