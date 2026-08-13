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

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Node\BulletList;
use Alto\Rst\Node\ContainerNode;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\EnumeratedList;
use Alto\Rst\Node\Inline\InlineText;
use Alto\Rst\Node\Inline\StandaloneHyperlink;
use Alto\Rst\Node\ListItem;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Section;
use Alto\Rst\Node\Text;
use Alto\Rst\Operation\SourcePatch;
use Alto\Rst\Operation\SourcePatchApplier;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Parser\ParseResult;
use Alto\Rst\Problem\ProblemReport;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Line;
use Alto\Rst\Source\Source;

/**
 * Plans conservative line wrapping for structurally simple prose.
 *
 * Direct document and section paragraphs, list-item paragraphs, and simple
 * admonition bodies are eligible. A candidate is returned only when
 * reparsing preserves the semantic document shape.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ParagraphWrapper
{
    private const array ADMONITION_NAMES = [
        'admonition',
        'attention',
        'caution',
        'danger',
        'error',
        'hint',
        'important',
        'note',
        'seealso',
        'tip',
        'warning',
    ];

    public function __construct(
        private int $width = 80,
    ) {
        if ($width < 1) {
            throw new InvalidArgumentException(\sprintf('Paragraph width must be >= 1, got %d.', $width));
        }
    }

    /**
     * @return list<SourcePatch>
     */
    public function patches(
        ParseResult $result,
        Source $source,
        ?Profile $profile = null,
    ): array {
        if (!$result->matchesSource($source)) {
            throw new InvalidArgumentException('Parse result was not produced from the paragraph wrapper source.');
        }

        if ($result->problems()->hasProblems()) {
            return [];
        }

        $references = $result->references();

        $patches = [];

        foreach ($this->eligibleParagraphs($result->document()) as $paragraph) {
            $patch = $this->paragraphPatch($paragraph, $source, $references, $references->problems());

            if (null !== $patch) {
                $patches[] = $patch;
            }
        }

        foreach ($this->eligibleAdmonitions($result->document()) as $directive) {
            $patch = $this->admonitionPatch($directive, $source, $profile ?? Profile::docutils());

            if (null !== $patch) {
                $patches[] = $patch;
            }
        }

        if ([] === $patches) {
            return [];
        }

        $profile ??= Profile::docutils();
        $candidate = new SourcePatchApplier()->apply($source->bytes, $patches);
        $reparsed = new BlockParser()->parse(Source::fromString($candidate->bytes), $profile);

        if ($reparsed->problems()->hasProblems()) {
            return [];
        }

        if ($this->problemSignature($references->problems()) !== $this->problemSignature($reparsed->references()->problems())) {
            return [];
        }

        if ($this->semanticShape($result->document()) !== $this->semanticShape($reparsed->document())) {
            return [];
        }

        return $patches;
    }

    /**
     * @return list<Paragraph>
     */
    private function eligibleParagraphs(Document $document): array
    {
        $paragraphs = [];
        $this->collectEligibleParagraphs($document->children(), $paragraphs);

        return $paragraphs;
    }

    /**
     * @param list<Node>      $nodes
     * @param list<Paragraph> $paragraphs
     */
    private function collectEligibleParagraphs(array $nodes, array &$paragraphs): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Paragraph) {
                $paragraphs[] = $node;

                continue;
            }

            if ($node instanceof Section) {
                $this->collectEligibleParagraphs($node->body(), $paragraphs);

                continue;
            }

            if ($node instanceof BulletList || $node instanceof EnumeratedList) {
                $this->collectListParagraphs($node->children(), $paragraphs);
            }
        }
    }

    /**
     * @param list<ListItem>  $items
     * @param list<Paragraph> $paragraphs
     */
    private function collectListParagraphs(array $items, array &$paragraphs): void
    {
        foreach ($items as $item) {
            foreach ($item->children() as $child) {
                if ($child instanceof Paragraph) {
                    $paragraphs[] = $child;

                    continue;
                }

                if ($child instanceof BulletList || $child instanceof EnumeratedList) {
                    $this->collectListParagraphs($child->children(), $paragraphs);
                }
            }
        }
    }

    /**
     * @return list<Directive>
     */
    private function eligibleAdmonitions(Document $document): array
    {
        $directives = [];
        $this->collectEligibleAdmonitions($document->children(), $directives);

        return $directives;
    }

    /**
     * @param list<Node>      $nodes
     * @param list<Directive> $directives
     */
    private function collectEligibleAdmonitions(array $nodes, array &$directives): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Directive && \in_array(strtolower($node->name), self::ADMONITION_NAMES, true)) {
                $directives[] = $node;

                continue;
            }

            if ($node instanceof Section) {
                $this->collectEligibleAdmonitions($node->body(), $directives);

                continue;
            }

            if ($node instanceof BulletList || $node instanceof EnumeratedList) {
                foreach ($node->children() as $item) {
                    $this->collectEligibleAdmonitions($item->children(), $directives);
                }
            }
        }
    }

    private function paragraphPatch(
        Paragraph $paragraph,
        Source $source,
        ReferenceGraph $references,
        ProblemReport $referenceProblems,
    ): ?SourcePatch {
        $lines = $this->linesForSpan($source, $paragraph->span());

        if ([] === $lines || !$this->hasOverlongLine($source, $lines)) {
            return null;
        }

        $first = $lines[0];
        $last = $lines[\count($lines) - 1];
        $textStart = $paragraph->text->span()->start;

        if (
            $paragraph->span()->start < $first->span->start
            || $paragraph->span()->start > $first->span->end()
            || $textStart < $paragraph->span()->start
            || $textStart > $first->span->end()
            || $paragraph->span()->end() !== $last->span->end()
        ) {
            return null;
        }

        if ($this->hasProblemIntersecting($referenceProblems, $paragraph->span())) {
            return null;
        }

        $outsidePrefix = $source->slice(ByteSpan::between($first->span->start, $paragraph->span()->start));
        $insidePrefix = $source->slice(ByteSpan::between($paragraph->span()->start, $textStart));
        $firstPrefix = $outsidePrefix . $insidePrefix;

        if (str_contains($firstPrefix, "\t")) {
            return null;
        }

        $continuationIndent = str_repeat(' ', self::characterLength($firstPrefix));
        $available = $this->width - self::characterLength($firstPrefix);

        if ($available < 1 || !$this->hasParagraphLayout($source, $lines, $continuationIndent)) {
            return null;
        }

        $inlineNodes = $references->inlineNodes($paragraph->text, false);

        if (null === $inlineNodes) {
            return null;
        }

        foreach ($inlineNodes as $node) {
            if (!$node instanceof InlineText && !$node instanceof StandaloneHyperlink) {
                return null;
            }
        }

        $raw = $paragraph->text->text;

        if (str_contains($raw, '\\') || 1 === preg_match('/[\t\v\f]/', $raw)) {
            return null;
        }

        $words = preg_split('/\s+/u', trim($raw));

        if (false === $words || [] === $words || [''] === $words) {
            return null;
        }

        foreach ($words as $word) {
            if (self::characterLength($word) > $available) {
                return null;
            }
        }

        $terminator = $this->paragraphTerminator($source, $lines);
        $replacement = $this->wrapWords($words, $insidePrefix, $continuationIndent, $terminator, $available);

        if ($source->slice($paragraph->span()) === $replacement) {
            return null;
        }

        return new SourcePatch($paragraph->span(), $replacement);
    }

    private function admonitionPatch(Directive $directive, Source $source, Profile $profile): ?SourcePatch
    {
        if (null === $directive->rawBody || !$profile->directives->has($directive->name)) {
            return null;
        }

        $body = $source->slice($directive->rawBody);
        $bodySource = Source::fromString($body);
        $lines = $bodySource->lines();

        if ([] === $lines) {
            return null;
        }

        $indent = $bodySource->slice(ByteSpan::between($lines[0]->span->start, $lines[0]->contentSpan()->start));

        if ('' === $indent || str_contains($indent, "\t") || !$this->hasUniformBodyLayout($bodySource, $indent)) {
            return null;
        }

        $available = $this->width - self::characterLength($indent);

        if ($available < 1) {
            return null;
        }

        $dedented = $this->dedent($bodySource);
        $nestedSource = Source::fromString($dedented);
        $nestedResult = new BlockParser()->parse($nestedSource, $profile);
        $children = $nestedResult->document()->children();

        if (1 !== \count($children) || !$children[0] instanceof Paragraph) {
            return null;
        }

        $nestedPatches = new self($available)->patches($nestedResult, $nestedSource, $profile);

        if ([] === $nestedPatches) {
            return null;
        }

        $wrapped = new SourcePatchApplier()->apply($dedented, $nestedPatches)->bytes;
        $wrapped = $this->withLineEnding(
            $wrapped,
            $this->paragraphTerminator($source, $this->linesForSpan($source, $directive->rawBody)),
        );
        $replacement = $this->indent($wrapped, $indent);

        if ($body === $replacement) {
            return null;
        }

        return new SourcePatch($directive->rawBody, $replacement);
    }

    private function hasProblemIntersecting(ProblemReport $problems, ByteSpan $span): bool
    {
        foreach ($problems as $problem) {
            if (null === $problem->span) {
                return true;
            }

            if ($problem->span->start < $span->end() && $problem->span->end() > $span->start) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Line>
     */
    private function linesForSpan(Source $source, ByteSpan $span): array
    {
        $lines = [];

        foreach ($source->lines() as $line) {
            if ($line->span->end() < $span->start) {
                continue;
            }

            if ($line->span->start > $span->end()) {
                break;
            }

            if ($line->span->start < $span->end() && $line->span->end() > $span->start) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param list<Line> $lines
     */
    private function hasOverlongLine(Source $source, array $lines): bool
    {
        foreach ($lines as $line) {
            if (self::characterLength($source->slice($line->span)) > $this->width) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Line> $lines
     */
    private function hasParagraphLayout(Source $source, array $lines, string $continuationIndent): bool
    {
        $lastIndex = \count($lines) - 1;
        $terminator = $lines[0]->terminator;

        foreach ($lines as $index => $line) {
            if ($line->isBlank()) {
                return false;
            }

            if ($index > 0) {
                $lineIndent = $source->slice(ByteSpan::between($line->span->start, $line->contentSpan()->start));

                if ($lineIndent !== $continuationIndent) {
                    return false;
                }
            }

            if ($index < $lastIndex && ('' === $terminator || $line->terminator !== $terminator)) {
                return false;
            }
        }

        return true;
    }

    private function hasUniformBodyLayout(Source $source, string $indent): bool
    {
        $lines = $source->lines();
        $lastIndex = \count($lines) - 1;
        $terminator = $lines[0]->terminator;

        foreach ($lines as $index => $line) {
            $lineIndent = $source->slice(ByteSpan::between($line->span->start, $line->contentSpan()->start));

            if ($line->isBlank() || $lineIndent !== $indent) {
                return false;
            }

            if ($index < $lastIndex && ('' === $terminator || $line->terminator !== $terminator)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Line> $lines
     */
    private function paragraphTerminator(Source $source, array $lines): string
    {
        foreach ($lines as $line) {
            if ('' !== $line->terminator) {
                return $line->terminator;
            }
        }

        $firstIndex = $lines[0]->index;

        for ($index = $firstIndex - 1; $index >= 0; --$index) {
            $terminator = $source->line($index)->terminator;

            if ('' !== $terminator) {
                return $terminator;
            }
        }

        return "\n";
    }

    /**
     * @param list<string> $words
     */
    private function wrapWords(
        array $words,
        string $firstPrefix,
        string $continuationIndent,
        string $terminator,
        int $available,
    ): string {
        $lines = [];
        $current = '';
        $length = 0;

        foreach ($words as $word) {
            $wordLength = self::characterLength($word);

            if ('' !== $current && $length + 1 + $wordLength > $available) {
                $lines[] = $current;
                $current = $word;
                $length = $wordLength;

                continue;
            }

            if ('' !== $current) {
                $current .= ' ';
                ++$length;
            }

            $current .= $word;
            $length += $wordLength;
        }

        $lines[] = $current;

        foreach ($lines as $index => &$line) {
            $line = (0 === $index ? $firstPrefix : $continuationIndent) . $line;
        }
        unset($line);

        return implode($terminator, $lines);
    }

    private function dedent(Source $source): string
    {
        $dedented = '';

        foreach ($source->lines() as $line) {
            $dedented .= $source->slice($line->contentSpan()) . $line->terminator;
        }

        return $dedented;
    }

    private function indent(string $bytes, string $indent): string
    {
        $source = Source::fromString($bytes);
        $indented = '';

        foreach ($source->lines() as $line) {
            $indented .= $indent . $source->slice($line->span) . $line->terminator;
        }

        return $indented;
    }

    private function withLineEnding(string $bytes, string $terminator): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $bytes);

        return false === $lines ? $bytes : implode($terminator, $lines);
    }

    /**
     * @return array{class: class-string<Node>, properties: array<string, mixed>, children: list<mixed>}
     */
    private function semanticShape(Node $node): array
    {
        $properties = [];

        foreach (get_object_vars($node) as $name => $value) {
            if (!\is_string($name)) {
                continue;
            }

            if ($value instanceof Node || $value instanceof ByteSpan) {
                continue;
            }

            if (\is_array($value) && $this->containsNodeOrSpan($value)) {
                continue;
            }

            $properties[$name] = $node instanceof Text && 'text' === $name
                ? self::normalizedWhitespace($value)
                : $this->normalizeValue($value);
        }

        $children = [];

        if ($node instanceof ContainerNode) {
            foreach ($node->children() as $child) {
                $children[] = $this->semanticShape($child);
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

    private static function normalizedWhitespace(mixed $value): mixed
    {
        if (!\is_string($value)) {
            return $value;
        }

        return preg_replace('/\s+/u', ' ', trim($value)) ?? $value;
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function problemSignature(ProblemReport $problems): array
    {
        $signature = [];

        foreach ($problems as $problem) {
            $signature[] = [$problem->severity->value, $problem->code, $problem->message];
        }

        return $signature;
    }

    private static function characterLength(string $content): int
    {
        $count = preg_match_all('/./su', $content);

        return false === $count ? \strlen($content) : $count;
    }
}
