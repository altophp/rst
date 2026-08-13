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

namespace Alto\Rst\Tests\Extension;

use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\ConversionResult;
use Alto\Rst\Convert\RstToMarkdown;
use Alto\Rst\Extension\DirectiveConversionContext;
use Alto\Rst\Extension\DirectiveHandler;
use Alto\Rst\Extension\DirectiveRenderContext;
use Alto\Rst\Extension\Extension;
use Alto\Rst\Node\Directive;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Profile\DirectiveSpec;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Render\HtmlPolicy;
use Alto\Rst\Render\HtmlRenderer;
use Alto\Rst\Render\RenderOptions;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\TestCase;

final class ExtensionSafetyTest extends TestCase
{
    public function testExtensionHandlerCannotEnableAFileReadingDirective(): void
    {
        $handler = new class implements DirectiveHandler {
            public function name(): string
            {
                return 'include';
            }

            public function renderHtml(
                Directive $directive,
                Source $source,
                Profile $profile,
                HtmlPolicy $htmlPolicy,
                DirectiveRenderContext $context,
            ): string {
                throw new \LogicException('Disabled include handler must not run.');
            }

            public function convertToMarkdown(
                Directive $directive,
                Source $source,
                Profile $profile,
                ConversionOptions $options,
                DirectiveConversionContext $context,
            ): ConversionResult {
                throw new \LogicException('Disabled include handler must not run.');
            }
        };
        $extension = new class ($handler) implements Extension {
            public function __construct(
                private readonly DirectiveHandler $handler,
            ) {}

            public function name(): string
            {
                return 'unsafe-test';
            }

            public function directives(): array
            {
                return [new DirectiveSpec('include', true, [], false)];
            }

            public function roles(): array
            {
                return [];
            }

            public function directiveHandlers(): array
            {
                return [$this->handler];
            }

            public function roleHandlers(): array
            {
                return [];
            }

            public function lintRules(): array
            {
                return [];
            }

            public function fixPasses(): array
            {
                return [];
            }

            public function formatterPasses(): array
            {
                return [];
            }

            public function statisticsProviders(): array
            {
                return [];
            }
        };
        $profile = Profile::docutils()->withExtension($extension);
        $source = Source::fromString(".. include:: secrets.txt\n");
        $parsed = new BlockParser()->parse($source, $profile);

        self::assertSame(
            "<!-- directive: include -->\n",
            new HtmlRenderer()->render(
                $parsed->document(),
                $source,
                new RenderOptions(profile: $profile),
                $parsed->references(),
            ),
        );

        $conversion = new RstToMarkdown()->convert($parsed->document(), $source, $profile);

        self::assertSame("<!-- rst: directive include -->\n", $conversion->output);
        self::assertSame(['directive:include' => 1], $conversion->report->countsByConstruct());
    }
}
