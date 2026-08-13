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

use Alto\Rst\Node\HyperlinkTarget;
use Alto\Rst\Node\Inline\InlineTarget;
use Alto\Rst\Node\Inline\InlineText;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Section;
use Alto\Rst\Node\Text;
use Alto\Rst\Parser\InlineParser;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Reference\DefinitionKind;
use Alto\Rst\Reference\ReferenceDefinition;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Reference\ReferenceName;
use Alto\Rst\Reference\ReferenceOccurrence;
use Alto\Rst\Reference\ReferenceType;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;

/**
 * Mutable bookkeeping for a single render call: the document-wide id
 * registry (duplicate ids get -1, -2 suffixes), the hyperlink target
 * table internal references resolve against, the anonymous target queue,
 * and a cache of inline parses so the collect pass and the render pass
 * see the same nodes.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class RenderState
{
    private readonly InlineParser $inlineParser;

    /**
     * @var array<string, true> every id already handed out
     */
    private array $claimedIds = [];

    /**
     * Resolution table keyed by normalized target name. The value is a
     * [kind, value] pair: 'url' with the destination, 'id' with the
     * emitted fragment id, or 'alias' with the referenced target name.
     * The first definition of a name wins.
     *
     * @var array<string, array{string, string}>
     */
    private array $targets = [];

    /**
     * Raw anonymous target strings in document order; null marks an
     * internal anonymous target, which v1 does not resolve.
     *
     * @var list<?string>
     */
    private array $anonymousTargets = [];

    private int $anonymousCursor = 0;

    /**
     * @var \SplObjectStorage<Section, string>
     */
    private \SplObjectStorage $sectionIds;

    /**
     * @var \SplObjectStorage<HyperlinkTarget, string>
     */
    private \SplObjectStorage $targetIds;

    /**
     * @var \SplObjectStorage<InlineTarget, string>
     */
    private \SplObjectStorage $inlineTargetIds;

    /**
     * @var array<string, string>
     */
    private array $inlineTargetIdsBySpan = [];

    /**
     * @var \SplObjectStorage<Text, list<Node>>
     */
    private \SplObjectStorage $inlineCache;

    /**
     * @var \SplObjectStorage<Node, string>
     */
    private \SplObjectStorage $graphIds;

    /**
     * @var \SplObjectStorage<ReferenceDefinition, string>
     */
    private \SplObjectStorage $definitionIds;

    /**
     * @var \SplObjectStorage<Node, list<string>>
     */
    private \SplObjectStorage $extraAnchorIds;

    /**
     * @var array<string, string>
     */
    private array $referenceIds = [];

    public function __construct(
        public readonly Source $source,
        public readonly HtmlPolicy $policy,
        public readonly ?Profile $profile,
        public readonly ReferenceGraph $references,
    ) {
        $this->inlineParser = new InlineParser(new ProblemCollector());
        $this->sectionIds = new \SplObjectStorage();
        $this->targetIds = new \SplObjectStorage();
        $this->inlineTargetIds = new \SplObjectStorage();
        $this->inlineCache = new \SplObjectStorage();
        $this->graphIds = new \SplObjectStorage();
        $this->definitionIds = new \SplObjectStorage();
        $this->extraAnchorIds = new \SplObjectStorage();
    }

    /**
     * Hands out a document-unique id for the given non-empty slug. The
     * first claim gets the slug itself, later claims get -1, -2 suffixes.
     */
    public function claimId(string $slug): string
    {
        $id = $slug;
        $suffix = 0;

        while (isset($this->claimedIds[$id])) {
            ++$suffix;
            $id = $slug . '-' . $suffix;
        }

        $this->claimedIds[$id] = true;

        return $id;
    }

    public function setSectionId(Section $section, string $id): void
    {
        $this->sectionIds->offsetSet($section, $id);
    }

    public function sectionId(Section $section): ?string
    {
        return $this->sectionIds->offsetExists($section) ? $this->sectionIds->offsetGet($section) : null;
    }

    public function setTargetId(HyperlinkTarget $target, string $id): void
    {
        $this->targetIds->offsetSet($target, $id);
    }

    public function targetId(HyperlinkTarget $target): ?string
    {
        return $this->targetIds->offsetExists($target) ? $this->targetIds->offsetGet($target) : null;
    }

    public function setInlineTargetId(InlineTarget $target, string $id): void
    {
        $this->inlineTargetIds->offsetSet($target, $id);
        $this->inlineTargetIdsBySpan[ReferenceGraph::spanKey($target->span())] = $id;
    }

    public function inlineTargetId(InlineTarget $target): ?string
    {
        return $this->inlineTargetIds->offsetExists($target) ? $this->inlineTargetIds->offsetGet($target) : null;
    }

    /**
     * Allocates presentation ids after the renderer's ordinary section and
     * target pre-pass. The semantic graph itself never owns HTML ids.
     */
    public function prepareReferenceIds(): void
    {
        $anonymousTarget = 0;

        foreach ($this->references->definitions() as $definition) {
            $node = $this->references->anchorNode($definition);
            $existing = $this->existingNodeId($node);

            if (null !== $existing && $node === $definition->node) {
                $this->definitionIds->offsetSet($definition, $existing);
                $this->graphIds->offsetSet($node, $existing);

                continue;
            }

            if (\in_array($definition->kind, [DefinitionKind::Footnote, DefinitionKind::Citation], true)) {
                $prefix = DefinitionKind::Footnote === $definition->kind ? 'footnote-' : 'citation-';
                $seed = ReferenceName::id($this->references->displayLabel($definition) ?? $definition->name);

                if ('' !== $seed) {
                    $id = $this->claimId($prefix . $seed);
                    $this->definitionIds->offsetSet($definition, $id);
                    $this->graphIds->offsetSet($node, $id);
                }

                continue;
            }

            if (!\in_array($definition->kind, [DefinitionKind::Hyperlink, DefinitionKind::InlineTarget, DefinitionKind::Section], true)
                || null !== $definition->destination
            ) {
                continue;
            }

            $seed = ReferenceName::id($definition->name);

            if ('' === $seed) {
                $seed = 'target-' . (++$anonymousTarget);
            }

            $id = $this->claimId($seed);
            $this->definitionIds->offsetSet($definition, $id);

            if (null === $existing && !$this->graphIds->offsetExists($node)) {
                $this->graphIds->offsetSet($node, $id);
            } else {
                $ids = $this->extraAnchorIds->offsetExists($node)
                    ? $this->extraAnchorIds->offsetGet($node)
                    : [];
                $ids[] = $id;
                $this->extraAnchorIds->offsetSet($node, $ids);
            }
        }

        foreach ($this->references->references() as $reference) {
            if (null === $reference->target) {
                continue;
            }

            if (\in_array($reference->type, [ReferenceType::Footnote, ReferenceType::Citation], true)) {
                $prefix = ReferenceType::Footnote === $reference->type ? 'footnote-reference-' : 'citation-reference-';
                $this->referenceIds[ReferenceGraph::spanKey($reference->span)] = $this->claimId($prefix . ReferenceName::id($reference->displayLabel ?? $reference->label));
            }
        }
    }

    public function definitionId(ReferenceDefinition $definition): ?string
    {
        if ($this->definitionIds->offsetExists($definition)) {
            return $this->definitionIds->offsetGet($definition);
        }

        $node = $this->references->anchorNode($definition);

        return $this->existingNodeId($node)
            ?? ($this->graphIds->offsetExists($node) ? $this->graphIds->offsetGet($node) : null);
    }

    public function nodeId(Node $node): ?string
    {
        return $this->graphIds->offsetExists($node) ? $this->graphIds->offsetGet($node) : null;
    }

    /**
     * @return list<string>
     */
    public function extraAnchorIds(Node $node): array
    {
        return $this->extraAnchorIds->offsetExists($node) ? $this->extraAnchorIds->offsetGet($node) : [];
    }

    public function referenceId(ReferenceOccurrence $reference): ?string
    {
        return $this->referenceIds[ReferenceGraph::spanKey($reference->span)] ?? null;
    }

    /**
     * @return list<string>
     */
    public function backReferenceIds(ReferenceDefinition $definition): array
    {
        $ids = [];

        foreach ($this->references->incoming($definition) as $reference) {
            $id = $this->referenceId($reference);

            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Records a target definition. The first definition of a name wins,
     * matching the reference resolution a reader would do top to bottom.
     *
     * @param 'url'|'id'|'alias' $kind
     */
    public function registerTarget(string $name, string $kind, string $value): void
    {
        $key = self::normalizeName($name);

        if ('' === $key || isset($this->targets[$key])) {
            return;
        }

        $this->targets[$key] = [$kind, $value];
    }

    /**
     * Resolves a target name to its final destination, following alias
     * chains (`.. _a: b_`) with a visited set so a cycle returns null.
     *
     * @return array{string, string}|null a ['url'|'id', value] pair
     */
    public function resolve(string $name): ?array
    {
        $key = self::normalizeName($name);
        $seen = [];

        while (isset($this->targets[$key])) {
            if (isset($seen[$key])) {
                return null;
            }

            $seen[$key] = true;
            [$kind, $value] = $this->targets[$key];

            if ('alias' !== $kind) {
                return [$kind, $value];
            }

            $key = self::normalizeName($value);
        }

        return null;
    }

    public function pushAnonymousTarget(?string $target): void
    {
        $this->anonymousTargets[] = $target;
    }

    /**
     * The next anonymous target in document order, or null when the
     * queue is exhausted or the slot is an internal anonymous target.
     */
    public function nextAnonymousTarget(): ?string
    {
        return $this->anonymousTargets[$this->anonymousCursor++] ?? null;
    }

    /**
     * The inline nodes for a Text placeholder, parsed once and cached so
     * the collect pass and the render pass agree.
     *
     * With $perSegment (table cells), each source line is parsed on its
     * own with its own base offset and the lists are joined with a
     * zero-length one-space InlineText: a multi-line cell slice is not
     * contiguous cell content (see DECISIONS.md, "A table cell is a
     * rectangle"), so markup never matches across the line break.
     *
     * @return list<Node>
     */
    public function inlineNodes(Text $text, bool $perSegment): array
    {
        if ($this->inlineCache->offsetExists($text)) {
            return $this->inlineCache->offsetGet($text);
        }

        $nodes = $this->references->inlineNodes($text, $perSegment);

        if (null !== $nodes) {
            $this->inlineCache->offsetSet($text, $nodes);

            return $nodes;
        }

        if (!$perSegment || !str_contains($text->text, "\n")) {
            $nodes = $this->inlineParser->parse($text->text, $text->span()->start);
        } else {
            $nodes = $this->parsePerSegment($text);
        }

        $this->inlineCache->offsetSet($text, $nodes);

        return $nodes;
    }

    /**
     * @return list<Node>
     */
    private function parsePerSegment(Text $text): array
    {
        $nodes = [];
        $sourceSegments = $text->sourceSegments();
        $offset = $text->span()->start;

        foreach (explode("\n", $text->text) as $index => $segment) {
            if (isset($sourceSegments[$index])) {
                $offset = $sourceSegments[$index]->start;
            }

            $trimmed = trim($segment);

            if ('' !== $trimmed) {
                $lead = \strlen($segment) - \strlen(ltrim($segment));

                if ([] !== $nodes) {
                    $nodes[] = new InlineText(ByteSpan::of($offset + $lead, 0), ' ');
                }

                foreach ($this->inlineParser->parse($trimmed, $offset + $lead) as $node) {
                    $nodes[] = $node;
                }
            }

            if (!isset($sourceSegments[$index + 1])) {
                $offset += \strlen($segment) + 1;
            }
        }

        return $nodes;
    }

    private static function normalizeName(string $name): string
    {
        return ReferenceName::normalize($name);
    }

    private function existingNodeId(Node $node): ?string
    {
        if ($node instanceof Section) {
            return $this->sectionId($node);
        }

        if ($node instanceof HyperlinkTarget) {
            return $this->targetId($node);
        }

        if ($node instanceof InlineTarget) {
            return $this->inlineTargetIdsBySpan[ReferenceGraph::spanKey($node->span())] ?? null;
        }

        return null;
    }
}
