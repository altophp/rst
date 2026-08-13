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

use Alto\Rst\Convert\ConversionResult;
use Alto\Rst\Node\Directive;

/**
 * Parent writer access for converting a directive's structured body.
 *
 * The callback stays inside the active writer so nested conversion inherits
 * targets, project references, file policy, and include state.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DirectiveConversionContext
{
    /**
     * @param \Closure(Directive): ConversionResult $bodyConverter
     *
     * @internal
     */
    public function __construct(
        private \Closure $bodyConverter,
    ) {}

    public function convertBody(Directive $directive): ConversionResult
    {
        return ($this->bodyConverter)($directive);
    }
}
