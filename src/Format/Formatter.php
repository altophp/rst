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

namespace Alto\Rst\Format;

use Alto\Rst\Node\BulletList;
use Alto\Rst\Node\ContainerNode;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Section;
use Alto\Rst\Operation\SourcePatch;
use Alto\Rst\Operation\SourcePatchApplier;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Line;
use Alto\Rst\Source\Source;

/**
 * Applies local formatting edits without rendering or mutating the ROM.
 *
 * Every edit is a byte-accurate SourcePatch over the original input. A
 * structural parse check rejects the bullet pass if changing markers would
 * alter the document tree.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Formatter
{
    public function format(
        string $bytes,
        ?FormatOptions $options = null,
        ?Profile $profile = null,
    ): FormatResult {
        $options ??= new FormatOptions();
        $profile ??= Profile::docutils();
        $source = Source::fromString($bytes);
        $parser = new BlockParser();
        $parsed = $parser->parse($source, $profile);
        $original = $parsed->document();

        $sectionPlan = $options->normalizeSectionAdornments
            ? $this->sectionPatches($source, $original)
            : ['patches' => [], 'skipped' => 0, 'changed' => 0];
        $patches = $sectionPlan['patches'];
        $bulletMarker = $options->bulletMarker;
        $applier = new SourcePatchApplier();

        if ([] !== $patches) {
            $sectionCandidate = $applier->apply($bytes, $patches);
            $formatted = $parser->parse(Source::fromString($sectionCandidate->bytes), $profile)->document();

            if (!$this->structurallyEquivalent($original, $formatted)) {
                $sectionPlan['skipped'] += $sectionPlan['changed'];
                $patches = [];
            }
        }

        $bulletPlan = null === $bulletMarker
            ? ['patches' => [], 'skipped' => 0]
            : $this->bulletPatches($source, $original, $bulletMarker);
        $candidatePatches = [...$patches, ...$bulletPlan['patches']];
        $candidate = $applier->apply($bytes, $candidatePatches);

        if ([] !== $bulletPlan['patches'] && null !== $bulletMarker) {
            $formatted = $parser->parse(Source::fromString($candidate->bytes), $profile)->document();

            if (!$this->structurallyEquivalent($original, $formatted)) {
                $bulletPlan['skipped'] = $this->changedBulletListCount($original, $bulletMarker);
                $bulletPlan['patches'] = [];
                $candidate = $applier->apply($bytes, $patches);
            }
        }

        $tablePatches = $options->alignSimpleTables
            ? new SimpleTableAligner()->patches($parsed, $source, $profile)
            : [];
        $acceptedPatches = [...$patches, ...$bulletPlan['patches'], ...$tablePatches];
        $candidate = $applier->apply($bytes, $acceptedPatches);
        $lineWidth = $options->lineWidth;

        if (null !== $lineWidth) {
            array_push(
                $acceptedPatches,
                ...new ParagraphWrapper($lineWidth)->patches($parsed, $source, $profile),
            );
            $candidate = $applier->apply($bytes, $acceptedPatches);
        }

        $skippedExtensionPasses = 0;
        $guardDocument = $parser->parse(Source::fromString($candidate->bytes), $profile)->document();

        foreach ($profile->extensions->formatterPasses() as $pass) {
            $passPatches = $pass->patches($original, $source, $profile);

            if ([] === $passPatches) {
                continue;
            }

            $extensionCandidate = $applier->apply($bytes, [...$acceptedPatches, ...$passPatches]);
            $formatted = $parser->parse(Source::fromString($extensionCandidate->bytes), $profile)->document();

            if (!$this->structurallyEquivalent($guardDocument, $formatted)) {
                ++$skippedExtensionPasses;

                continue;
            }

            array_push($acceptedPatches, ...$passPatches);
            $candidate = $extensionCandidate;
            $guardDocument = $formatted;
        }

        return new FormatResult(
            $candidate->bytes,
            $candidate->patches,
            $sectionPlan['skipped'],
            $bulletPlan['skipped'],
            $skippedExtensionPasses,
        );
    }

    /**
     * @return array{patches: list<SourcePatch>, skipped: int, changed: int}
     */
    private function sectionPatches(Source $source, Document $document): array
    {
        $patches = [];
        $skipped = 0;
        $changed = 0;

        foreach ($document->descendants() as $node) {
            if (!$node instanceof Section) {
                continue;
            }

            $width = $this->displayWidth(trim($node->title->text->text));

            if (null === $width || $width < 1) {
                ++$skipped;

                continue;
            }

            $titleLine = $this->lineContaining($source, $node->title->span()->start);

            if (null === $titleLine) {
                ++$skipped;

                continue;
            }

            $lineIndexes = $node->hasOverline
                ? [$titleLine->index - 1, $titleLine->index + 1]
                : [$titleLine->index + 1];
            $titlePatches = [];

            foreach ($lineIndexes as $lineIndex) {
                if ($lineIndex < 0 || $lineIndex >= $source->lineCount()) {
                    $titlePatches = [];

                    break;
                }

                $line = $source->line($lineIndex);
                $run = $this->adornmentRun($source, $line, $node->adornment);

                if (null === $run) {
                    $titlePatches = [];

                    break;
                }

                $replacement = str_repeat($node->adornment, $width);

                if ($source->slice($run) !== $replacement) {
                    $titlePatches[] = new SourcePatch($run, $replacement);
                }
            }

            if ([] === $titlePatches && $this->sectionNeedsChange($source, $lineIndexes, $node->adornment, $width)) {
                ++$skipped;

                continue;
            }

            if ([] !== $titlePatches) {
                ++$changed;
            }

            array_push($patches, ...$titlePatches);
        }

        return ['patches' => $patches, 'skipped' => $skipped, 'changed' => $changed];
    }

    /**
     * @param list<int> $lineIndexes
     */
    private function sectionNeedsChange(Source $source, array $lineIndexes, string $adornment, int $width): bool
    {
        foreach ($lineIndexes as $lineIndex) {
            if ($lineIndex < 0 || $lineIndex >= $source->lineCount()) {
                return true;
            }

            $run = $this->adornmentRun($source, $source->line($lineIndex), $adornment);

            if (null === $run || $source->slice($run) !== str_repeat($adornment, $width)) {
                return true;
            }
        }

        return false;
    }

    private function adornmentRun(Source $source, Line $line, string $adornment): ?ByteSpan
    {
        $content = $line->contentSpan();
        $length = strspn($source->bytes, $adornment, $content->start, $content->length);

        if (0 === $length) {
            return null;
        }

        $tail = substr($source->bytes, $content->start + $length, $content->length - $length);

        if ('' !== trim($tail, " \t\v\f")) {
            return null;
        }

        return ByteSpan::of($content->start, $length);
    }

    /**
     * @return array{patches: list<SourcePatch>, skipped: int}
     */
    private function bulletPatches(Source $source, Document $document, string $target): array
    {
        $patches = [];
        $skipped = 0;

        foreach ($document->descendants() as $node) {
            if (!$node instanceof BulletList || $node->marker === $target) {
                continue;
            }

            if (!\in_array($node->marker, ['-', '*', '+'], true) || $this->hasAdjacentBulletList($source, $node)) {
                ++$skipped;

                continue;
            }

            $listPatches = [];

            foreach ($node->children() as $item) {
                $line = $this->lineContaining($source, $item->span()->start);

                if (null === $line) {
                    $listPatches = [];

                    break;
                }

                $markerSpan = ByteSpan::of($line->contentSpan()->start, \strlen($node->marker));

                if ($source->slice($markerSpan) !== $node->marker) {
                    $listPatches = [];

                    break;
                }

                $listPatches[] = new SourcePatch($markerSpan, $target);
            }

            if ([] === $listPatches) {
                ++$skipped;

                continue;
            }

            array_push($patches, ...$listPatches);
        }

        return ['patches' => $patches, 'skipped' => $skipped];
    }

    private function hasAdjacentBulletList(Source $source, BulletList $list): bool
    {
        $first = $this->lineContaining($source, $list->span()->start);
        $last = $this->lineContaining($source, max($list->span()->start, $list->span()->end() - 1));

        if (null === $first || null === $last) {
            return true;
        }

        foreach ([$this->previousNonBlank($source, $first->index), $this->nextNonBlank($source, $last->index)] as $line) {
            if (null === $line || $line->indentWidth !== $first->indentWidth) {
                continue;
            }

            $content = $source->slice($line->contentSpan());

            if (1 === preg_match('/^(?:[-*+]|\x{2022}|\x{2023}|\x{2043})(?:[ \t]|$)/u', $content)) {
                return true;
            }
        }

        return false;
    }

    private function previousNonBlank(Source $source, int $lineIndex): ?Line
    {
        for ($index = $lineIndex - 1; $index >= 0; --$index) {
            $line = $source->line($index);

            if (!$line->isBlank()) {
                return $line;
            }
        }

        return null;
    }

    private function nextNonBlank(Source $source, int $lineIndex): ?Line
    {
        for ($index = $lineIndex + 1, $count = $source->lineCount(); $index < $count; ++$index) {
            $line = $source->line($index);

            if (!$line->isBlank()) {
                return $line;
            }
        }

        return null;
    }

    private function lineContaining(Source $source, int $offset): ?Line
    {
        foreach ($source->lines() as $line) {
            if ($offset >= $line->span->start && $offset <= $line->span->end()) {
                return $line;
            }
        }

        return null;
    }

    private function displayWidth(string $title): ?int
    {
        if (1 !== preg_match('//u', $title)) {
            return null;
        }

        if (1 === preg_match('/[\p{M}\x{200D}\x{1F1E6}-\x{1F1FF}\x{1F3FB}-\x{1F3FF}]/u', $title)) {
            return null;
        }

        $hasUnicode = 1 === preg_match('/[^\x00-\x7F]/', $title);

        if ($hasUnicode && !\function_exists('mb_strwidth')) {
            return null;
        }

        $width = 0;

        foreach (explode("\t", $title) as $index => $part) {
            if ($index > 0) {
                $width += 8 - ($width % 8);
            }

            $width += $hasUnicode ? mb_strwidth($part, 'UTF-8') : \strlen($part);
        }

        return $width;
    }

    private function changedBulletListCount(Document $document, string $target): int
    {
        $count = 0;

        foreach ($document->descendants() as $node) {
            if ($node instanceof BulletList && $node->marker !== $target) {
                ++$count;
            }
        }

        return $count;
    }

    private function structurallyEquivalent(Document $left, Document $right): bool
    {
        return $this->nodeShape($left) === $this->nodeShape($right);
    }

    /**
     * @return array{class: class-string<Node>, properties: array<array-key, mixed>, children: list<mixed>}
     */
    private function nodeShape(Node $node): array
    {
        $properties = [];

        foreach (get_object_vars($node) as $name => $value) {
            if ($value instanceof Node || $value instanceof ByteSpan) {
                continue;
            }

            if ($node instanceof BulletList && 'marker' === $name) {
                continue;
            }

            if (\is_array($value) && $this->containsNodeOrSpan($value)) {
                continue;
            }

            $properties[$name] = $this->normalizeValue($value);
        }

        $children = [];

        if ($node instanceof ContainerNode) {
            foreach ($node->children() as $child) {
                $children[] = $this->nodeShape($child);
            }
        }

        return [
            'class' => $node::class,
            'properties' => $properties,
            'children' => $children,
        ];
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private function containsNodeOrSpan(array $values): bool
    {
        foreach ($values as $value) {
            if ($value instanceof Node || $value instanceof ByteSpan) {
                return true;
            }

            if (\is_array($value) && $this->containsNodeOrSpan($value)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return [$value::class, $value->value];
        }

        if ($value instanceof \UnitEnum) {
            return [$value::class, $value->name];
        }

        if (\is_array($value)) {
            return array_map($this->normalizeValue(...), $value);
        }

        return $value;
    }
}
