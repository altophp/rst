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

namespace Alto\Rst\Tests\Render;

use Alto\Rst\Profile\Profile;
use Alto\Rst\Render\HtmlPolicy;
use Alto\Rst\Render\RenderOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RenderOptions::class)]
final class RenderOptionsTest extends TestCase
{
    public function testDefaultsToTheSafePolicy(): void
    {
        self::assertSame(HtmlPolicy::safe(), (new RenderOptions())->htmlPolicy);
    }

    public function testKeepsAnExplicitPolicy(): void
    {
        $policy = HtmlPolicy::safe()->withAllowedSchemes('https');
        $options = new RenderOptions(htmlPolicy: $policy);

        self::assertSame($policy, $options->htmlPolicy);
    }

    public function testDefaultsToNoProfile(): void
    {
        self::assertNull((new RenderOptions())->profile);
    }

    public function testKeepsAnExplicitProfile(): void
    {
        $profile = Profile::symfony();
        $options = new RenderOptions(profile: $profile);

        self::assertSame($profile, $options->profile);
    }
}
