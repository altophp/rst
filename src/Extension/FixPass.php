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
use Alto\Rst\Operation\SourcePatch;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Source\Source;

/**
 * One mechanically safe, source-preserving extension fix.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface FixPass
{
    public function name(): string;

    /**
     * @return list<SourcePatch>
     */
    public function patches(Document $document, Source $source, Profile $profile): array;
}
