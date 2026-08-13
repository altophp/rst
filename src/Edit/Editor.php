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

namespace Alto\Rst\Edit;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\HyperlinkTarget;
use Alto\Rst\Node\Section;
use Alto\Rst\Operation\SourcePatch;
use Alto\Rst\Operation\SourcePatchApplier;
use Alto\Rst\Parser\ParseResult;
use Alto\Rst\Reference\DefinitionKind;
use Alto\Rst\Reference\ReferenceDefinition;
use Alto\Rst\Reference\ReferenceName;
use Alto\Rst\Reference\ReferenceOccurrence;
use Alto\Rst\Reference\ReferenceType;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Line;
use Alto\Rst\Source\Source;

/**
 * Plans typed edits against one immutable original RST source.
 *
 * Section handles are scoped to the ParseResult passed to the constructor.
 * After applying and reparsing the output, create a new Editor and use the
 * Section instances from the new ParseResult. This editor never retargets old
 * handles because every patch continues to address the original source bytes.
 *
 * Replacing a section body removes all of that section's body nodes, including
 * nested subsections, but never a following sibling section. An empty
 * replacement leaves the section header with no body. An originally empty
 * section accepts a body through an insertion immediately after its underline.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class Editor
{
    /**
     * @var list<SourcePatch>
     */
    private array $patches = [];

    /**
     * @var \SplObjectStorage<Section, null>
     */
    private \SplObjectStorage $sections;

    /**
     * @var \SplObjectStorage<Directive, null>
     */
    private \SplObjectStorage $directives;

    /**
     * @var \SplObjectStorage<HyperlinkTarget, null>
     */
    private \SplObjectStorage $targets;

    private readonly SourcePatchApplier $applier;

    public function __construct(
        private readonly Source $source,
        private readonly ParseResult $result,
    ) {
        if (!$result->matchesSource($source)) {
            throw new InvalidArgumentException('Parse result was not produced from the editor source.');
        }

        $this->applier = new SourcePatchApplier();
        $this->sections = new \SplObjectStorage();
        $this->directives = new \SplObjectStorage();
        $this->targets = new \SplObjectStorage();

        foreach ($result->document()->descendants() as $node) {
            if ($node instanceof Section) {
                $this->sections[$node] = null;
            } elseif ($node instanceof Directive) {
                $this->directives[$node] = null;
            } elseif ($node instanceof HyperlinkTarget) {
                $this->targets[$node] = null;
            }
        }
    }

    public function replaceSectionBody(Section $section, string $rst): self
    {
        $this->assertOwnedSection($section);
        $headerEnd = $this->sectionHeaderEnd($section);
        $span = ByteSpan::between($headerEnd, $section->span()->end());
        $body = $this->normalizeFragment($rst, $this->sectionEol($section));
        $replacement = '' === $body ? '' : $this->sectionEol($section) . $this->sectionEol($section) . $body;

        $this->record(new SourcePatch($span, $replacement));

        return $this;
    }

    public function setDirectiveOption(Directive $directive, string $name, string $value = ''): self
    {
        $this->assertOwnedDirective($directive);
        $name = $this->validOptionName($name);
        $fields = $this->directiveOptionFields($directive);
        $matches = array_values(array_filter(
            $fields,
            static fn(array $field): bool => strtolower($field['name']) === strtolower($name),
        ));

        if (\count($matches) > 1) {
            throw new InvalidArgumentException(\sprintf('Directive option "%s" is duplicated and cannot be edited safely.', $name));
        }

        if (1 === \count($matches)) {
            $field = $matches[0];
            $replacement = $this->optionFieldBytes(
                $field['indent'],
                $field['rawName'],
                $value,
                $this->lineEol($field['line']),
                $field['continuationIndent'],
            );
            $this->record(new SourcePatch(
                ByteSpan::between($field['line']->span->start, $field['lastLine']->span->end()),
                $replacement,
            ));

            return $this;
        }

        $this->insertDirectiveOption($directive, $fields, $name, $value);

        return $this;
    }

    public function removeDirectiveOption(Directive $directive, string $name): self
    {
        $this->assertOwnedDirective($directive);
        $name = $this->validOptionName($name);
        $matches = array_values(array_filter(
            $this->directiveOptionFields($directive),
            static fn(array $field): bool => strtolower($field['name']) === strtolower($name),
        ));

        if ([] === $matches) {
            return $this;
        }

        if (\count($matches) > 1) {
            throw new InvalidArgumentException(\sprintf('Directive option "%s" is duplicated and cannot be edited safely.', $name));
        }

        $field = $matches[0];
        $this->record(new SourcePatch(
            $field['lastLine']->spanWithTerminator()->union($field['line']->span),
            '',
        ));

        return $this;
    }

    /**
     * Renames one explicit target and every safely resolved local reference.
     *
     * The operation is rejected when reference coverage is incomplete,
     * definitions are ambiguous, the new name collides, or an incoming source
     * form cannot be rewritten without guessing.
     */
    public function renameTarget(HyperlinkTarget $target, string $newName): self
    {
        $this->assertOwnedTarget($target);
        $newName = $this->validTargetName($newName);
        $graph = $this->result->references();

        if (!$graph->isCoverageComplete()) {
            throw new InvalidArgumentException('Target rename requires complete document reference coverage.');
        }

        $definitions = array_values(array_filter(
            $graph->definitions(DefinitionKind::Hyperlink),
            static fn(ReferenceDefinition $definition): bool => $definition->node === $target,
        ));

        if (1 !== \count($definitions)) {
            throw new InvalidArgumentException('Target handle has no unique hyperlink definition.');
        }

        $definition = $definitions[0];
        $sameNameDefinitions = $graph->explicitDefinitions()[$definition->normalizedName] ?? [];

        if (1 !== \count($sameNameDefinitions)) {
            throw new InvalidArgumentException(\sprintf('Target "%s" is ambiguous and cannot be renamed safely.', $target->name));
        }

        $collision = $graph->explicitDefinitions()[ReferenceName::normalize($newName)] ?? [];

        foreach ($collision as $other) {
            if ($other->node !== $target) {
                throw new InvalidArgumentException(\sprintf('Target name "%s" already exists.', $newName));
            }
        }

        $patches = [$this->targetNamePatch($target, $newName)];

        foreach ($this->targets as $otherTarget) {
            if (
                $otherTarget !== $target
                && $definition->normalizedName === $this->indirectTargetName($otherTarget)
            ) {
                $patches[] = $this->targetDestinationPatch($otherTarget, $newName);
            }
        }

        foreach ($graph->incoming($definition) as $reference) {
            if ($reference->normalizedLabel === $definition->normalizedName) {
                $patches[] = $this->referenceRenamePatch($reference, $newName);
            }
        }

        $this->recordMany($patches);

        return $this;
    }

    public function insertTopLevel(string $rst): self
    {
        $eol = $this->documentEol();
        $fragment = $this->normalizeFragment($rst, $eol);

        if ('' === $fragment) {
            return $this;
        }

        $bytes = $this->source->bytes;
        $contentBytes = $this->source->hasBom() ? substr($bytes, 3) : $bytes;
        $normalizedContentBytes = str_replace(["\r\n", "\r"], "\n", $contentBytes);
        $endsWithEol = 1 === preg_match('/(?:\r\n|\r|\n)\z/', $contentBytes);
        $endsWithBlankLine = str_ends_with($normalizedContentBytes, "\n\n");

        if ('' === $contentBytes) {
            $prefix = '';
        } elseif ($endsWithBlankLine) {
            $prefix = '';
        } elseif ($endsWithEol) {
            $prefix = $eol;
        } else {
            $prefix = $eol . $eol;
        }

        $suffix = $endsWithEol ? $eol : '';
        $this->record(new SourcePatch(
            ByteSpan::of(\strlen($bytes), 0),
            $prefix . $fragment . $suffix,
        ));

        return $this;
    }

    public function toRst(): string
    {
        return $this->applier->apply($this->source->bytes, $this->patches)->bytes;
    }

    /**
     * Patches in original-source application order.
     *
     * @return list<SourcePatch>
     */
    public function patches(): array
    {
        return $this->patches;
    }

    private function assertOwnedSection(Section $section): void
    {
        if (!$this->sections->offsetExists($section)) {
            throw new InvalidArgumentException('Section handle does not belong to this editor parse result.');
        }
    }

    private function assertOwnedDirective(Directive $directive): void
    {
        if (!$this->directives->offsetExists($directive)) {
            throw new InvalidArgumentException('Directive handle does not belong to this editor parse result.');
        }
    }

    private function assertOwnedTarget(HyperlinkTarget $target): void
    {
        if (!$this->targets->offsetExists($target)) {
            throw new InvalidArgumentException('Target handle does not belong to this editor parse result.');
        }

        if ($target->anonymous) {
            throw new InvalidArgumentException('Anonymous targets cannot be renamed.');
        }
    }

    private function sectionHeaderEnd(Section $section): int
    {
        $titleLine = $this->lineContaining($section->title->span());
        $underlineIndex = $titleLine->index + 1;

        if ($underlineIndex >= $this->source->lineCount()) {
            throw new InvalidArgumentException('Section handle has no underline in the editor source.');
        }

        $underline = $this->source->line($underlineIndex);
        $headerEnd = $underline->span->end();

        if ($headerEnd > $section->span()->end()) {
            throw new InvalidArgumentException('Section handle does not match the editor source.');
        }

        return $headerEnd;
    }

    /**
     * @return non-empty-string
     */
    private function sectionEol(Section $section): string
    {
        $titleLine = $this->lineContaining($section->title->span());
        $underlineIndex = $titleLine->index + 1;

        if ($underlineIndex < $this->source->lineCount()) {
            $terminator = $this->source->line($underlineIndex)->terminator;

            if ('' !== $terminator) {
                return $terminator;
            }
        }

        if ('' !== $titleLine->terminator) {
            return $titleLine->terminator;
        }

        return $this->documentEol();
    }

    private function lineContaining(ByteSpan $span): Line
    {
        foreach ($this->source->lines() as $line) {
            if ($span->start >= $line->span->start && $span->end() <= $line->span->end()) {
                return $line;
            }
        }

        throw new InvalidArgumentException('Section handle does not match the editor source.');
    }

    private function lineContainingOffset(int $offset): Line
    {
        foreach ($this->source->lines() as $line) {
            if ($offset >= $line->span->start && $offset <= $line->span->end()) {
                return $line;
            }
        }

        throw new InvalidArgumentException(\sprintf('Byte offset %d does not belong to the editor source.', $offset));
    }

    /**
     * @return non-empty-string
     */
    private function documentEol(): string
    {
        foreach (array_reverse($this->source->lines()) as $line) {
            if ('' !== $line->terminator) {
                return $line->terminator;
            }
        }

        return "\n";
    }

    private function normalizeFragment(string $rst, string $eol): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $rst);

        if ("\n" !== $eol) {
            $normalized = str_replace("\n", $eol, $normalized);
        }

        return rtrim($normalized, "\r\n");
    }

    /**
     * @return non-empty-string
     */
    private function lineEol(Line $line): string
    {
        return '' === $line->terminator ? $this->documentEol() : $line->terminator;
    }

    private function validOptionName(string $name): string
    {
        if (
            '' === $name
            || $name !== trim($name)
            || 1 !== preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name)
        ) {
            throw new InvalidArgumentException(\sprintf('Invalid directive option name "%s".', $name));
        }

        return $name;
    }

    /**
     * @return list<array{
     *     name: string,
     *     rawName: string,
     *     line: Line,
     *     lastLine: Line,
     *     indent: string,
     *     continuationIndent: string
     * }>
     */
    private function directiveOptionFields(Directive $directive): array
    {
        $firstLine = $this->lineContainingOffset($directive->span()->start);
        $lines = $this->source->lines();
        $index = $firstLine->index + 1;

        while ($index < \count($lines) && $lines[$index]->isBlank()) {
            ++$index;
        }

        $fields = [];

        while ($index < \count($lines) && $lines[$index]->span->start <= $directive->span()->end()) {
            $line = $lines[$index];
            $content = $this->source->slice($line->contentSpan());

            if (1 !== preg_match('/^:((?:\\\\.|[^\\\\:])+):(?:[ \t]+(.*))?$/', rtrim($content), $matches)) {
                break;
            }

            $rawName = $matches[1];
            $name = trim(preg_replace('/\\\\(.)/', '$1', $rawName) ?? $rawName);
            $lastLine = $line;
            $continuationIndent = $this->source->slice(ByteSpan::between($line->span->start, $line->contentSpan()->start)) . '   ';
            ++$index;

            while (
                $index < \count($lines)
                && !$lines[$index]->isBlank()
                && $lines[$index]->indentWidth > $line->indentWidth
                && $lines[$index]->span->start <= $directive->span()->end()
            ) {
                $lastLine = $lines[$index];
                $continuationIndent = $this->source->slice(ByteSpan::between(
                    $lastLine->span->start,
                    $lastLine->contentSpan()->start,
                ));
                ++$index;
            }

            $fields[] = [
                'name' => $name,
                'rawName' => $rawName,
                'line' => $line,
                'lastLine' => $lastLine,
                'indent' => $this->source->slice(ByteSpan::between($line->span->start, $line->contentSpan()->start)),
                'continuationIndent' => $continuationIndent,
            ];

            if ($index < \count($lines) && $lines[$index]->isBlank()) {
                break;
            }
        }

        return $fields;
    }

    /**
     * @param list<array{
     *     name: string,
     *     rawName: string,
     *     line: Line,
     *     lastLine: Line,
     *     indent: string,
     *     continuationIndent: string
     * }> $fields
     */
    private function insertDirectiveOption(Directive $directive, array $fields, string $name, string $value): void
    {
        $firstLine = $this->lineContainingOffset($directive->span()->start);

        if ([] === $fields) {
            $anchor = $firstLine;
            $indent = $this->source->slice(ByteSpan::between($firstLine->span->start, $firstLine->contentSpan()->start)) . '   ';
        } else {
            $last = $fields[\count($fields) - 1];
            $anchor = $last['lastLine'];
            $indent = $last['indent'];
        }

        $eol = $this->lineEol($anchor);
        $field = $this->optionFieldBytes($indent, $name, $value, $eol, $indent . '   ');

        if ('' === $anchor->terminator) {
            $offset = $anchor->span->end();
            $replacement = $eol . $field;
        } else {
            $offset = $anchor->spanWithTerminator()->end();
            $replacement = $field . $eol;
        }

        $this->record(new SourcePatch(ByteSpan::of($offset, 0), $replacement));
    }

    /**
     * @param non-empty-string $eol
     */
    private function optionFieldBytes(
        string $indent,
        string $rawName,
        string $value,
        string $eol,
        string $continuationIndent,
    ): string {
        $value = $this->normalizeFragment($value, $eol);
        $lines = '' === $value ? [] : explode($eol, $value);
        $field = $indent . ':' . $rawName . ':';

        if ([] === $lines) {
            return $field;
        }

        $field .= ' ' . array_shift($lines);

        foreach ($lines as $line) {
            $field .= $eol . $continuationIndent . $line;
        }

        return $field;
    }

    private function validTargetName(string $name): string
    {
        if (
            '' === $name
            || $name !== trim($name)
            || str_contains($name, "\0")
            || str_contains($name, "\n")
            || str_contains($name, "\r")
            || str_contains($name, '`')
        ) {
            throw new InvalidArgumentException(\sprintf('Invalid target name "%s".', $name));
        }

        return $name;
    }

    private function targetNamePatch(HyperlinkTarget $target, string $newName): SourcePatch
    {
        $line = $this->lineContainingOffset($target->span()->start);
        $bytes = $this->source->slice($line->span);
        $bomOffset = 0 === $line->index && str_starts_with($bytes, "\xEF\xBB\xBF") ? 3 : 0;
        $bytes = substr($bytes, $bomOffset);

        if (1 !== preg_match('/^[ \t]*\\.\\.[ \t]+_/', $bytes, $prefix)) {
            throw new InvalidArgumentException('Target declaration source cannot be rewritten safely.');
        }

        $start = \strlen($prefix[0]);

        if ('`' === ($bytes[$start] ?? '')) {
            $end = strpos($bytes, '`', $start + 1);

            if (false === $end || ':' !== ($bytes[$end + 1] ?? '')) {
                throw new InvalidArgumentException('Target declaration source cannot be rewritten safely.');
            }

            $length = $end + 1 - $start;
        } else {
            $end = $this->unescapedColon($bytes, $start);

            if (null === $end) {
                throw new InvalidArgumentException('Target declaration source cannot be rewritten safely.');
            }

            $length = $end - $start;
        }

        return new SourcePatch(
            ByteSpan::of($line->span->start + $bomOffset + $start, $length),
            $this->targetToken($newName),
        );
    }

    private function indirectTargetName(HyperlinkTarget $target): ?string
    {
        if (!str_ends_with($target->target, '_')) {
            return null;
        }

        $name = substr($target->target, 0, -1);

        if (\strlen($name) >= 2 && '`' === $name[0] && '`' === $name[\strlen($name) - 1]) {
            $name = substr($name, 1, -1);
        }

        return ReferenceName::normalize($name);
    }

    private function targetDestinationPatch(HyperlinkTarget $target, string $newName): SourcePatch
    {
        $line = $this->lineContainingOffset($target->span()->start);
        $bytes = $this->source->slice($line->span);
        $bomOffset = 0 === $line->index && str_starts_with($bytes, "\xEF\xBB\xBF") ? 3 : 0;
        $bytes = substr($bytes, $bomOffset);

        if (1 !== preg_match('/^[ \t]*\\.\\.[ \t]+_/', $bytes, $prefix)) {
            throw new InvalidArgumentException('Indirect target declaration cannot be rewritten safely.');
        }

        $nameStart = \strlen($prefix[0]);

        if ('`' === ($bytes[$nameStart] ?? '')) {
            $nameEnd = strpos($bytes, '`', $nameStart + 1);
            $colon = false === $nameEnd ? null : $nameEnd + 1;
        } else {
            $colon = $this->unescapedColon($bytes, $nameStart);
        }

        if (null === $colon || ':' !== ($bytes[$colon] ?? '')) {
            throw new InvalidArgumentException('Indirect target declaration cannot be rewritten safely.');
        }

        $destinationStart = $colon + 1;
        $destinationStart += strspn($bytes, " \t", $destinationStart);
        $destinationLength = \strlen($bytes) - $destinationStart;

        if (
            $destinationLength <= 0
            || trim(substr($bytes, $destinationStart)) !== $target->target
        ) {
            throw new InvalidArgumentException('Indirect target declaration cannot be rewritten safely.');
        }

        return new SourcePatch(
            ByteSpan::of(
                $line->span->start + $bomOffset + $destinationStart,
                \strlen($target->target),
            ),
            $this->targetToken($newName) . '_',
        );
    }

    private function unescapedColon(string $bytes, int $start): ?int
    {
        $escaped = false;

        for ($offset = $start, $length = \strlen($bytes); $offset < $length; ++$offset) {
            if (!$escaped && ':' === $bytes[$offset]) {
                return $offset;
            }

            if (!$escaped && '\\' === $bytes[$offset]) {
                $escaped = true;
            } else {
                $escaped = false;
            }
        }

        return null;
    }

    private function targetToken(string $name): string
    {
        if (1 === preg_match('/^[0-9A-Za-z\x80-\xff]+(?:[-._+][0-9A-Za-z\x80-\xff]+)*$/', $name)) {
            return $name;
        }

        return '`' . $name . '`';
    }

    private function referenceRenamePatch(ReferenceOccurrence $reference, string $newName): SourcePatch
    {
        $raw = $this->source->slice($reference->span);
        $replacement = match ($reference->type) {
            ReferenceType::Hyperlink => $this->renamedHyperlinkReference($raw, $newName),
            ReferenceType::SphinxRef => $this->renamedSphinxReference($raw, $newName, null !== $reference->explicitTitle),
            default => throw new InvalidArgumentException(\sprintf('Reference form "%s" cannot be renamed safely.', $reference->type->value)),
        };

        return new SourcePatch($reference->span, $replacement);
    }

    private function renamedHyperlinkReference(string $raw, string $newName): string
    {
        if (1 === preg_match('/^[0-9A-Za-z\x80-\xff]+(?:[-._+:][0-9A-Za-z\x80-\xff]+)*_$/', $raw)) {
            $token = $this->targetToken($newName);

            return str_starts_with($token, '`') ? $token . '_' : $token . '_';
        }

        if (1 === preg_match('/^(.*<)[ \t]*([^<>]+)_([ \t]*>)`_$/s', $raw, $matches)) {
            if (str_starts_with($this->targetToken($newName), '`')) {
                throw new InvalidArgumentException('Embedded hyperlink aliases cannot safely target a phrase name.');
            }

            return $matches[1] . $newName . '_' . $matches[3] . '`_';
        }

        if (1 === preg_match('/^`[^`]+`_$/s', $raw)) {
            return '`' . $newName . '`_';
        }

        throw new InvalidArgumentException(\sprintf('Hyperlink reference "%s" cannot be renamed safely.', $raw));
    }

    private function renamedSphinxReference(string $raw, string $newName, bool $explicitTitle): string
    {
        $open = strpos($raw, '`');
        $close = strrpos($raw, '`');

        if (false === $open || false === $close || $close <= $open) {
            throw new InvalidArgumentException('Sphinx reference source cannot be rewritten safely.');
        }

        $content = substr($raw, $open + 1, $close - $open - 1);

        if ($explicitTitle) {
            if (1 !== preg_match('/^(.*<)[^<>]+(>)$/s', $content, $matches)) {
                throw new InvalidArgumentException('Sphinx reference title source cannot be rewritten safely.');
            }

            $content = $matches[1] . $newName . $matches[2];
        } else {
            $content = $newName;
        }

        return substr($raw, 0, $open + 1) . $content . substr($raw, $close);
    }

    private function record(SourcePatch $patch): void
    {
        $this->recordMany([$patch]);
    }

    /**
     * @param list<SourcePatch> $patches
     */
    private function recordMany(array $patches): void
    {
        $candidates = $this->patches;

        foreach ($patches as $patch) {
            if ($this->source->slice($patch->span) === $patch->replacement) {
                continue;
            }

            $duplicate = false;

            foreach ($candidates as $existing) {
                if (
                    $existing->span->start === $patch->span->start
                    && $existing->span->length === $patch->span->length
                    && $existing->replacement === $patch->replacement
                ) {
                    $duplicate = true;

                    break;
                }
            }

            if (!$duplicate) {
                $candidates[] = $patch;
            }
        }

        $result = $this->applier->apply($this->source->bytes, $candidates);
        $this->patches = $result->patches;
    }
}
