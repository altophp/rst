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

/**
 * Safe-by-default HTML output policy.
 *
 * URL policy: an allowlist of schemes checked on link destinations. A
 * destination whose scheme is not allowed keeps its element but empties
 * the attribute value; the text content is never dropped. Schemes are
 * detected after control bytes and whitespace (0x00 to 0x20), literal or
 * percent-encoded, are removed, because browsers ignore those inside a
 * URL. A destination with no scheme (relative reference, fragment, or
 * scheme-relative reference) is always allowed.
 *
 * V0 emits no anchor elements yet; the inline phase applies this policy
 * the day links render.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class HtmlPolicy
{
    /**
     * @var array<string, true>
     */
    private const array SAFE_SCHEMES = [
        'http' => true,
        'https' => true,
        'mailto' => true,
        'tel' => true,
    ];

    private static ?self $safe = null;

    /**
     * @param array<string, true> $schemes allowed URL schemes, lowercased
     */
    private function __construct(
        private readonly array $schemes,
    ) {
    }

    /**
     * The default: URL schemes restricted to http, https, mailto, tel,
     * plus relative references and fragments.
     */
    public static function safe(): self
    {
        return self::$safe ??= new self(self::SAFE_SCHEMES);
    }

    /**
     * Replaces the allowed-scheme allowlist.
     */
    public function withAllowedSchemes(string ...$schemes): self
    {
        $map = [];

        foreach ($schemes as $scheme) {
            $map[strtolower($scheme)] = true;
        }

        return new self($map);
    }

    /**
     * @return list<string>
     */
    public function allowedSchemes(): array
    {
        return array_keys($this->schemes);
    }

    public function isUrlAllowed(string $url): bool
    {
        // Strip control bytes and whitespace (0x00 to 0x20), literal or
        // percent-encoded, so a scheme split by a tab or newline is
        // detected the way a browser would collapse it.
        $probe = (string) preg_replace('/%0[0-9A-Fa-f]|%1[0-9A-Fa-f]|%20|[\x00-\x20]/', '', $url);

        if (1 !== preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $probe, $matches)) {
            // No scheme: a relative reference or fragment is safe.
            return true;
        }

        return isset($this->schemes[strtolower($matches[1])]);
    }
}
