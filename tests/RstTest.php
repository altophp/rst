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

namespace Alto\Rst\Tests;

use Alto\Rst\Parser\ParseResult;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Reference\ReferenceStatus;
use Alto\Rst\Render\RenderOptions;
use Alto\Rst\Rst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Rst::class)]
#[CoversClass(ParseResult::class)]
final class RstTest extends TestCase
{
    public function testProfileFactories(): void
    {
        self::assertSame('docutils', Rst::docutils()->profileName());
        self::assertSame('sphinx', Rst::sphinx()->profileName());
        self::assertSame('symfony', Rst::symfony()->profileName());
    }

    public function testProfileExposesTheCapabilitySet(): void
    {
        self::assertTrue(Rst::docutils()->profile()->directives->has('code-block'));
        self::assertTrue(Rst::sphinx()->profile()->directives->has('code-block'));
        self::assertTrue(Rst::symfony()->profile()->directives->has('configuration-block'));
    }

    public function testNoProfileEnablesAFileReadingDirective(): void
    {
        foreach ([Rst::docutils(), Rst::sphinx(), Rst::symfony()] as $rst) {
            $directives = $rst->profile()->directives;

            self::assertFalse($directives->isEnabled('include'), $rst->profileName());
            self::assertFalse($directives->isEnabled('raw'), $rst->profileName());
            self::assertFalse($directives->isEnabled('literalinclude'), $rst->profileName());
        }
    }

    public function testParseReturnsDocumentAndProblems(): void
    {
        $result = Rst::docutils()->parse("Title\n=====\n\nBody text.\n");

        self::assertCount(1, $result->document()->children());
        self::assertFalse($result->problems()->hasProblems());
    }

    public function testParseExposesTheDocumentWideReferenceGraph(): void
    {
        $result = Rst::sphinx()->parse("See guide_.\n\n.. _guide: https://example.com\n");
        $reference = $result->references()->references()[0];

        self::assertSame(ReferenceStatus::Resolved, $reference->status);
        self::assertSame('https://example.com', $reference->target?->destination);
        self::assertFalse($result->references()->problems()->hasProblems());
    }

    public function testToHtmlEndToEnd(): void
    {
        $rst = "Guide\n=====\n\nRead this first.\n\n.. note::\n\n   Be careful.\n";

        $html = Rst::docutils()->toHtml($rst);

        self::assertStringContainsString('<section id="guide">', $html);
        self::assertStringContainsString('<h1>Guide</h1>', $html);
        self::assertStringContainsString('<p>Read this first.</p>', $html);
        self::assertStringContainsString('<div class="admonition note">', $html);
    }

    public function testToHtmlEscapesUntrustedInput(): void
    {
        $html = Rst::docutils()->toHtml("<script>alert(1)</script>\n");

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRenderOptionProfileAlsoBuildsTheReferenceGraph(): void
    {
        $source = "See :ref:`label`.\n\n.. _label:\n\nTarget\n======\n";
        $html = Rst::docutils()->toHtml($source, new RenderOptions(profile: Profile::sphinx()));

        self::assertStringContainsString('<a href="#label">Target</a>', $html);
    }
}
