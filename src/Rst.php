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

namespace Alto\Rst;

use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Parser\ParseResult;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Render\HtmlRenderer;
use Alto\Rst\Render\RenderOptions;
use Alto\Rst\Source\Source;

/**
 * Entry point for the Alto Rst engine.
 *
 * Profiles select the accepted reStructuredText dialect, from the docutils
 * base language to the Sphinx dialect and the Symfony documentation
 * conventions. Profile-specific capabilities are delivered incrementally;
 * see ROADMAP.md.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Rst
{
    private function __construct(
        private Profile $profile,
    ) {}

    public static function docutils(): self
    {
        return new self(Profile::docutils());
    }

    public static function sphinx(): self
    {
        return new self(Profile::sphinx());
    }

    public static function symfony(): self
    {
        return new self(Profile::symfony());
    }

    /**
     * The capability set backing this instance: which directives and roles
     * the selected dialect recognizes.
     */
    public function profile(): Profile
    {
        return $this->profile;
    }

    public function profileName(): string
    {
        return $this->profile->name;
    }

    public function parse(string $source): ParseResult
    {
        return new BlockParser()->parse(Source::fromString($source), $this->profile);
    }

    public function toHtml(string $source, ?RenderOptions $renderOptions = null): string
    {
        $input = Source::fromString($source);
        $effectiveProfile = null === $renderOptions || null === $renderOptions->profile
            ? $this->profile
            : $renderOptions->profile;
        $result = new BlockParser()->parse($input, $effectiveProfile);
        $renderOptions = null === $renderOptions
            ? new RenderOptions(profile: $effectiveProfile)
            : new RenderOptions($renderOptions->htmlPolicy, $effectiveProfile);

        return new HtmlRenderer()->render($result->document(), $input, $renderOptions, $result->references());
    }
}
