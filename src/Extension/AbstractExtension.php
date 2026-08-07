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

namespace Alto\Rst\Extension;

/**
 * Convenience base for extensions that implement only selected contracts.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
abstract readonly class AbstractExtension implements Extension
{
    public function directives(): array
    {
        return [];
    }

    public function roles(): array
    {
        return [];
    }

    public function directiveHandlers(): array
    {
        return [];
    }

    public function roleHandlers(): array
    {
        return [];
    }

    public function lintRules(): array
    {
        return [];
    }

    public function fixPasses(): array
    {
        return [];
    }

    public function formatterPasses(): array
    {
        return [];
    }

    public function statisticsProviders(): array
    {
        return [];
    }
}
