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

namespace Alto\Rst\Render;

use Alto\Rst\Profile\Profile;

/**
 * Options for a single render call. Defaults to the safe HTML policy and
 * no profile: without a profile the renderer keeps the v0 directive
 * behavior (the base admonition set, everything else an inert comment
 * placeholder). With a profile, directives the profile enables render to
 * real HTML; see HtmlRenderer.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class RenderOptions
{
    public HtmlPolicy $htmlPolicy;

    public function __construct(
        ?HtmlPolicy $htmlPolicy = null,
        public ?Profile $profile = null,
    ) {
        $this->htmlPolicy = $htmlPolicy ?? HtmlPolicy::safe();
    }
}
