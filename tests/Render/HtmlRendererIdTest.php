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

use Alto\Rst\Render\HtmlRenderer;
use Alto\Rst\Render\RenderState;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Document-wide id assignment: duplicate slugs get -1, -2 suffixes, and
 * sections share one registry with hyperlink targets.
 */
#[CoversClass(HtmlRenderer::class)]
#[CoversClass(RenderState::class)]
final class HtmlRendererIdTest extends TestCase
{
    public function testDuplicateSectionSlugsGetNumberedSuffixes(): void
    {
        $html = self::render("Same\n====\n\nSame\n====\n\nSame\n====\n");

        self::assertStringContainsString('<section id="same">', $html);
        self::assertStringContainsString('<section id="same-1">', $html);
        self::assertStringContainsString('<section id="same-2">', $html);
    }

    public function testNestedSectionsShareTheRegistry(): void
    {
        $html = self::render("Top\n===\n\nSame\n----\n\nSame\n~~~~\n");

        self::assertStringContainsString('<section id="same">', $html);
        self::assertStringContainsString('<section id="same-1">', $html);
    }

    public function testATargetBeforeASectionSharesTheSectionId(): void
    {
        $html = self::render("Intro\n=====\n\n.. _intro:\n\nBody.\n");

        self::assertStringContainsString('<section id="intro">', $html);
        self::assertStringNotContainsString('<span id=', $html);
    }

    public function testAnExplicitSlugCollidingWithASuffixStaysUnique(): void
    {
        $html = self::render("Same\n====\n\nSame 1\n======\n\nSame\n====\n");

        self::assertStringContainsString('<section id="same">', $html);
        self::assertStringContainsString('<section id="same-1">', $html);
        self::assertStringContainsString('<section id="same-2">', $html);
        self::assertSame(1, substr_count($html, 'id="same-1"'));
    }

    private static function render(string $rst): string
    {
        $source = Source::fromString($rst);

        return new HtmlRenderer()->render(Rst::symfony()->parse($rst)->document(), $source);
    }
}
