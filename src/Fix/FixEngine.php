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

namespace Alto\Rst\Fix;

use Alto\Rst\Node\Directive;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\HyperlinkTarget;
use Alto\Rst\Node\Inline\InterpretedText;
use Alto\Rst\Node\Text;
use Alto\Rst\Operation\SourcePatch;
use Alto\Rst\Operation\SourcePatchApplier;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Reference\DefinitionKind;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Line;
use Alto\Rst\Source\Source;

/**
 * Applies local source fixes without rendering or mutating the ROM.
 *
 * Raw and recovery-sensitive ranges are left byte for byte unchanged.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FixEngine
{
    public function fix(
        string $bytes,
        ?FixOptions $options = null,
        ?Profile $profile = null,
    ): FixResult {
        $options ??= new FixOptions();
        $profile ??= Profile::docutils();
        $source = Source::fromString($bytes);
        $parser = new BlockParser();
        $original = $parser->parse($source, $profile);
        $protected = ProtectedSpanIndex::fromParseResult($original);
        $skipped = 0;
        $deletedLines = [];
        $patches = [];

        if (!$original->problems()->hasProblems()) {
            if ($options->blankLineAfterAnchor) {
                array_push($patches, ...$this->blankLineAfterAnchorPatches($source, $original->references()));
            }

            if ($options->blankLineBeforeDirectiveBody) {
                array_push($patches, ...$this->blankLineBeforeDirectiveBodyPatches($source, $original->document()));
            }

            if ($options->normalizeDefaultRoleAsLiteral) {
                array_push(
                    $patches,
                    ...$this->defaultRoleLiteralPatches($source, $original->document(), $original->references(), $protected),
                );
            }
        }

        if (null !== $options->maxBlankLines) {
            [$blankLinePatches, $deletedLines, $blankLineSkipped] = $this->blankLinePatches(
                $source,
                $protected,
                $options->maxBlankLines,
            );
            array_push($patches, ...$blankLinePatches);
            $skipped += $blankLineSkipped;
        }

        if ($options->removeTrailingWhitespace) {
            [$trailingPatches, $trailingSkipped] = $this->trailingWhitespacePatches(
                $source,
                $protected,
                $deletedLines,
            );
            array_push($patches, ...$trailingPatches);
            $skipped += $trailingSkipped;
        }

        foreach ($profile->extensions->fixPasses() as $pass) {
            array_push($patches, ...$pass->patches($original->document(), $source, $profile));
        }

        $patched = new SourcePatchApplier()->apply($bytes, $patches);
        $parseResult = $parser->parse(Source::fromString($patched->bytes), $profile);

        return new FixResult($patched->bytes, $patched->patches, $parseResult, $skipped);
    }

    /**
     * @return list<SourcePatch>
     */
    private function blankLineAfterAnchorPatches(
        Source $source,
        ReferenceGraph $references,
    ): array {
        $patches = [];

        foreach ($references->definitions(DefinitionKind::Hyperlink) as $definition) {
            $target = $definition->node;

            if (
                !$target instanceof HyperlinkTarget
                || $target->anonymous
                || '' !== $target->target
                || null === ($line = $this->lineContaining($source, $target->span()->start))
                || $line->index + 1 >= $source->lineCount()
            ) {
                continue;
            }

            $next = $source->line($line->index + 1);

            if ($next->isBlank()) {
                continue;
            }

            $terminator = '' === $line->terminator ? $next->terminator : $line->terminator;
            if ('' !== $terminator) {
                $patches[] = new SourcePatch(ByteSpan::of($next->span->start, 0), $terminator);
            }
        }

        return $patches;
    }

    /**
     * @return list<SourcePatch>
     */
    private function blankLineBeforeDirectiveBodyPatches(
        Source $source,
        Document $document,
    ): array {
        $patches = [];

        foreach ($document->descendants() as $node) {
            if (
                !$node instanceof Directive
                || null === $node->rawBody
                || null === ($bodyLine = $this->lineContaining($source, $node->rawBody->start))
                || 0 === $bodyLine->index
            ) {
                continue;
            }

            $previous = $source->line($bodyLine->index - 1);

            if ($previous->isBlank()) {
                continue;
            }

            $terminator = '' === $previous->terminator ? $bodyLine->terminator : $previous->terminator;
            if ('' !== $terminator) {
                $patches[] = new SourcePatch(ByteSpan::of($bodyLine->span->start, 0), $terminator);
            }
        }

        return $patches;
    }

    /**
     * @return list<SourcePatch>
     */
    private function defaultRoleLiteralPatches(
        Source $source,
        Document $document,
        ReferenceGraph $references,
        ProtectedSpanIndex $protected,
    ): array {
        $patches = [];

        foreach ($document->descendants() as $node) {
            if (!$node instanceof Text) {
                continue;
            }

            foreach ($references->inlineNodes($node, false) ?? [] as $inline) {
                if (
                    !$inline instanceof InterpretedText
                    || null !== $inline->role
                    || $protected->intersects($inline->span())
                ) {
                    continue;
                }

                $sourceText = $source->slice($inline->span());

                if (
                    !str_starts_with($sourceText, '`')
                    || !str_ends_with($sourceText, '`')
                    || str_starts_with($sourceText, '``')
                    || str_ends_with($sourceText, '``')
                ) {
                    continue;
                }

                $patches[] = new SourcePatch($inline->span(), '`'.$sourceText.'`');
            }
        }

        return $patches;
    }

    private function lineContaining(Source $source, int $offset): ?Line
    {
        foreach ($source->lines() as $line) {
            if ($offset >= $line->span->start && $offset < $line->spanWithTerminator()->end()) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @return array{list<SourcePatch>, array<int, true>, int}
     */
    private function blankLinePatches(Source $source, ProtectedSpanIndex $protected, int $max): array
    {
        $patches = [];
        $deletedLines = [];
        $skipped = 0;
        $run = [];

        foreach ($source->lines() as $line) {
            if ($line->isBlank()) {
                $run[] = $line;

                continue;
            }

            $skipped += $this->flushBlankLineRun($run, $source, $protected, $max, $patches, $deletedLines);
            $run = [];
        }

        $skipped += $this->flushBlankLineRun($run, $source, $protected, $max, $patches, $deletedLines);

        return [$patches, $deletedLines, $skipped];
    }

    /**
     * @param list<Line>        $run
     * @param list<SourcePatch> $patches
     * @param array<int, true>  $deletedLines
     */
    private function flushBlankLineRun(
        array $run,
        Source $source,
        ProtectedSpanIndex $protected,
        int $max,
        array &$patches,
        array &$deletedLines,
    ): int {
        $extra = \count($run) - $max;

        if ($extra <= 0) {
            return 0;
        }

        foreach ($run as $line) {
            if ($protected->intersects($line->spanWithTerminator())) {
                return $extra;
            }
        }

        foreach (\array_slice($run, $max) as $line) {
            $span = $line->spanWithTerminator();

            if ($span->isEmpty()) {
                continue;
            }

            $patches[] = new SourcePatch($span, '');
            $deletedLines[$line->index] = true;
        }

        return 0;
    }

    /**
     * @param array<int, true> $deletedLines
     *
     * @return array{list<SourcePatch>, int}
     */
    private function trailingWhitespacePatches(
        Source $source,
        ProtectedSpanIndex $protected,
        array $deletedLines,
    ): array {
        $patches = [];
        $skipped = 0;

        foreach ($source->lines() as $line) {
            if (isset($deletedLines[$line->index])) {
                continue;
            }

            $content = $source->slice($line->span);
            $trimmed = rtrim($content, " \t\v\f");
            $length = \strlen($content) - \strlen($trimmed);

            if (0 === $length) {
                continue;
            }

            $span = ByteSpan::of($line->span->end() - $length, $length);

            if ($protected->intersects($span)) {
                ++$skipped;

                continue;
            }

            $patches[] = new SourcePatch($span, '');
        }

        return [$patches, $skipped];
    }
}
