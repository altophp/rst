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
use Alto\Rst\Node\DirectiveBodyKind;
use Alto\Rst\Node\Document;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Render\HtmlPolicy;
use Alto\Rst\Render\HtmlRenderer;
use Alto\Rst\Render\RenderOptions;
use Alto\Rst\Source\Source;

/**
 * Symfony's configuration tabs. HTML keeps the group boundary; Markdown
 * writes the nested code blocks in source order because it has no tab set.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ConfigurationBlockHandler implements DirectiveHandler
{
    public function name(): string
    {
        return 'configuration-block';
    }

    public function renderHtml(
        Directive $directive,
        Source $source,
        Profile $profile,
        HtmlPolicy $htmlPolicy,
        DirectiveRenderContext $context,
    ): string {
        $body = DirectiveBodyKind::Blocks === $directive->bodyKind
            ? $context->renderBody($directive)
            : $this->renderLegacyBody($directive, $source, $profile, $htmlPolicy);

        return "<div class=\"configuration-block\">\n" . $body . "</div>\n";
    }

    public function convertToMarkdown(
        Directive $directive,
        Source $source,
        Profile $profile,
        ConversionOptions $options,
        DirectiveConversionContext $context,
    ): ConversionResult {
        $issues = [ConversionIssue::approximated(
            'directive:configuration-block',
            'Markdown has no configuration tabs; nested blocks were written in source order.',
            $directive->span(),
        )];
        $output = '';

        if (null !== $directive->rawBody) {
            $conversion = $context->convertBody($directive);
            $output = $conversion->output;

            foreach ($conversion->report->issues as $issue) {
                $issues[] = new ConversionIssue(
                    $issue->construct,
                    $issue->message,
                    $issue->kind,
                    $directive->span(),
                );
            }
        }

        return new ConversionResult($output, new ConversionReport($issues));
    }

    private function renderLegacyBody(
        Directive $directive,
        Source $source,
        Profile $profile,
        HtmlPolicy $htmlPolicy,
    ): string {
        $nested = $this->bodyDocument($directive, $source, $profile);

        return null === $nested
            ? ''
            : new HtmlRenderer()->render(
                $nested['document'],
                $nested['source'],
                new RenderOptions($htmlPolicy, $profile),
                $nested['references'],
            );
    }

    /**
     * @return array{
     *     source: Source,
     *     document: Document,
     *     references: ReferenceGraph
     * }|null
     */
    private function bodyDocument(Directive $directive, Source $source, Profile $profile): ?array
    {
        if (null === $directive->rawBody) {
            return null;
        }

        $nestedSource = Source::fromString(self::dedent($source->slice($directive->rawBody)));
        $parsed = new BlockParser()->parse($nestedSource, $profile);

        return [
            'source' => $nestedSource,
            'document' => $parsed->document(),
            'references' => $parsed->references(),
        ];
    }

    private static function dedent(string $text): string
    {
        $lines = explode("\n", $text);
        $indent = null;

        foreach ($lines as $line) {
            if ('' === trim($line)) {
                continue;
            }

            $width = \strlen($line) - \strlen(ltrim($line, ' '));
            $indent = null === $indent ? $width : min($indent, $width);
        }

        if (null === $indent || 0 === $indent) {
            return $text;
        }

        foreach ($lines as &$line) {
            if ('' !== $line) {
                $line = substr($line, min($indent, \strlen($line)));
            }
        }

        return implode("\n", $lines);
    }
}
