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

namespace Alto\Rst\Extension\Symfony;

use Alto\Rst\Convert\ConversionIssue;
use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\ConversionReport;
use Alto\Rst\Convert\ConversionResult;
use Alto\Rst\Extension\DirectiveConversionContext;
use Alto\Rst\Extension\DirectiveHandler;
use Alto\Rst\Extension\DirectiveRenderContext;
use Alto\Rst\Node\Directive;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Render\HtmlPolicy;
use Alto\Rst\Source\Source;

/**
 * Symfony's screencast callout.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ScreencastHandler implements DirectiveHandler
{
    public function name(): string
    {
        return 'screencast';
    }

    public function renderHtml(
        Directive $directive,
        Source $source,
        Profile $profile,
        HtmlPolicy $htmlPolicy,
        DirectiveRenderContext $context,
    ): string {
        return "<aside class=\"screencast\">\n"
            ."<p class=\"screencast-title\">Screencast</p>\n"
            .$context->renderBody($directive)
            ."</aside>\n";
    }

    public function convertToMarkdown(
        Directive $directive,
        Source $source,
        Profile $profile,
        ConversionOptions $options,
        DirectiveConversionContext $context,
    ): ConversionResult {
        $body = $context->convertBody($directive);
        $issues = [
            ConversionIssue::approximated(
                'directive:screencast',
                'Screencast callout was rendered as a Markdown tip.',
                $directive->span(),
            ),
            ...$body->report->issues,
        ];

        $arguments = array_values(array_filter(
            array_map('trim', $directive->arguments),
            static fn (string $argument): bool => '' !== $argument,
        ));

        if ([] !== $arguments) {
            $issues[] = ConversionIssue::lossy(
                'directive:screencast:arguments',
                'Screencast arguments were dropped.',
                $directive->span(),
            );
        }

        $content = '[!TIP]';
        $nested = rtrim($body->output, "\n");

        if ('' !== $nested) {
            $content .= "\n\n".$nested;
        }

        return new ConversionResult(self::quote($content), new ConversionReport($issues));
    }

    private static function quote(string $block): string
    {
        $lines = explode("\n", $block);

        return implode("\n", array_map(
            static fn (string $line): string => '' === $line ? '>' : '> '.$line,
            $lines,
        ));
    }
}
