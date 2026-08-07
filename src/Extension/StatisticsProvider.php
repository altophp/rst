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

use Alto\Rst\Node\Document;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Source\Source;

/**
 * Adds one namespaced statistics group for an active extension.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface StatisticsProvider
{
    public function name(): string;

    /**
     * @return array<string, bool|float|int|string|null>
     */
    public function collect(Document $document, Source $source, Profile $profile): array;
}
