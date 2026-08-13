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

use Alto\Rst\Node\Directive;

/**
 * Parent renderer access for rendering a directive's structured body.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DirectiveRenderContext
{
    /**
     * @param \Closure(Directive): string $bodyRenderer
     *
     * @internal
     */
    public function __construct(
        private \Closure $bodyRenderer,
    ) {}

    public function renderBody(Directive $directive): string
    {
        return ($this->bodyRenderer)($directive);
    }
}
