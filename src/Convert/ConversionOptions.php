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

namespace Alto\Rst\Convert;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Security\FileAccessPolicy;

/**
 * Output shape for both conversion directions.
 *
 * The Markdown-side and reStructuredText-side settings share one object
 * because a round trip uses both, and splitting them would force callers to
 * keep two objects in sync for the one case that matters most.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ConversionOptions
{
    private const string ADORNMENT_CHARS = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    /**
     * @param list<string> $sectionAdornments adornment characters per section level, outermost first
     */
    public function __construct(
        public FenceStyle $fenceStyle = FenceStyle::Backtick,
        public LinkStyle $linkStyle = LinkStyle::Preserve,
        public HeadingStyle $headingStyle = HeadingStyle::Atx,
        public AdmonitionStyle $admonitionStyle = AdmonitionStyle::GithubAlert,
        public string $bulletMarker = '-',
        public array $sectionAdornments = ['=', '-', '~', '^', '"', "'"],
        public int $indentWidth = 4,
        public string $codeBlockDirective = 'code-block',
        public ?FileAccessPolicy $fileAccessPolicy = null,
        public bool $implicitSectionReferences = false,
        public bool $allowRawHtml = false,
    ) {
        if (1 !== \strlen($bulletMarker) || !str_contains('-*+', $bulletMarker)) {
            throw new InvalidArgumentException(sprintf('Bullet marker must be one of "-", "*", "+", got "%s".', $bulletMarker));
        }

        if ([] === $sectionAdornments) {
            throw new InvalidArgumentException('Section adornments must not be empty.');
        }

        foreach ($sectionAdornments as $adornment) {
            if (1 !== \strlen($adornment) || !str_contains(self::ADORNMENT_CHARS, $adornment)) {
                throw new InvalidArgumentException(sprintf('Invalid section adornment character "%s".', $adornment));
            }
        }

        if ($indentWidth < 1) {
            throw new InvalidArgumentException(sprintf('Indent width must be >= 1, got %d.', $indentWidth));
        }

        if ('' === $codeBlockDirective) {
            throw new InvalidArgumentException('Code block directive name must not be empty.');
        }
    }

    /**
     * Defaults matching the Symfony documentation conventions: the adornment
     * order counted in the UX corpus and the Sphinx code-block directive.
     */
    public static function symfony(
        ?FileAccessPolicy $fileAccessPolicy = null,
        bool $allowRawHtml = false,
    ): self {
        return new self(
            sectionAdornments: ['=', '-', '~', '.', '"'],
            indentWidth: 4,
            codeBlockDirective: 'code-block',
            fileAccessPolicy: $fileAccessPolicy,
            implicitSectionReferences: true,
            allowRawHtml: $allowRawHtml,
        );
    }

    public function adornmentFor(int $level): string
    {
        $index = max(1, $level) - 1;

        return $this->sectionAdornments[$index] ?? $this->sectionAdornments[\count($this->sectionAdornments) - 1];
    }

    public function indent(int $depth = 1): string
    {
        return str_repeat(' ', $this->indentWidth * max(0, $depth));
    }
}
