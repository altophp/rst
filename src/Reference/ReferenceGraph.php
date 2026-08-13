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

namespace Alto\Rst\Reference;

use Alto\Rst\Node\Directive;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\Inline\CitationReference;
use Alto\Rst\Node\Inline\Emphasis;
use Alto\Rst\Node\Inline\FootnoteReference;
use Alto\Rst\Node\Inline\HyperlinkReference;
use Alto\Rst\Node\Inline\InlineLiteral;
use Alto\Rst\Node\Inline\InlineTarget;
use Alto\Rst\Node\Inline\InlineText;
use Alto\Rst\Node\Inline\InterpretedText;
use Alto\Rst\Node\Inline\StandaloneHyperlink;
use Alto\Rst\Node\Inline\Strong;
use Alto\Rst\Node\Inline\SubstitutionReference;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Section;
use Alto\Rst\Node\Text;
use Alto\Rst\Problem\ProblemReport;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;

/**
 * Immutable document-wide definitions, references, and resolutions.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ReferenceGraph
{
    /**
     * @var array<int, list<ReferenceOccurrence>>
     */
    private array $incomingByDefinition;

    /**
     * @param list<ReferenceDefinition>                $definitions
     * @param list<ReferenceOccurrence>                $references
     * @param array<string, ReferenceDefinition>       $targets
     * @param array<string, ReferenceDefinition>       $explicitTargets
     * @param array<string, list<ReferenceDefinition>> $explicitDefinitions
     * @param array<string, list<ReferenceOccurrence>> $referencesBySpan
     * @param array<string, list<Node>>                $inlineNodes
     * @param array<int, Node>                         $anchorNodes
     * @param array<int, string>                       $definitionDisplayLabels
     * @param array<string, list<Node>>                $directiveInlineNodes
     */
    public function __construct(
        private array $definitions,
        private array $references,
        private array $targets,
        private array $explicitTargets,
        private array $explicitDefinitions,
        private array $referencesBySpan,
        private array $inlineNodes,
        private array $anchorNodes,
        private array $definitionDisplayLabels,
        private ProblemReport $problems,
        private bool $coverageComplete = true,
        private array $directiveInlineNodes = [],
    ) {
        $definitionsByKey = [];
        $incoming = [];

        foreach ($definitions as $definition) {
            $key = self::definitionKey($definition);
            $definitionsByKey[$key][] = $definition;
            $incoming[spl_object_id($definition)] = [];
        }

        foreach ($references as $reference) {
            $matched = [];

            if (null !== $reference->target) {
                $matched[spl_object_id($reference->target)] = $reference->target;
            }

            foreach ($definitionsByKey[self::referenceKey($reference)] ?? [] as $definition) {
                $matched[spl_object_id($definition)] = $definition;
            }

            foreach ($matched as $definition) {
                $id = spl_object_id($definition);

                if (isset($incoming[$id])) {
                    $incoming[$id][] = $reference;
                }
            }
        }

        $this->incomingByDefinition = $incoming;
    }

    public static function fromDocument(Document $document, Source $source, ?Profile $profile = null): self
    {
        return self::fromBytes($document, $source->bytes, $profile);
    }

    /**
     * Builds from source bytes without retaining Source's scanned line model.
     *
     * @internal
     */
    public static function fromBytes(Document $document, string $source, ?Profile $profile = null): self
    {
        return new ReferenceGraphBuilder($source, $profile)->build($document);
    }

    /**
     * @return list<ReferenceDefinition>
     */
    public function definitions(?DefinitionKind $kind = null): array
    {
        if (null === $kind) {
            return $this->definitions;
        }

        return array_values(array_filter(
            $this->definitions,
            static fn(ReferenceDefinition $definition): bool => $kind === $definition->kind,
        ));
    }

    /**
     * @return list<ReferenceOccurrence>
     */
    public function references(?ReferenceType $type = null): array
    {
        if (null === $type) {
            return $this->references;
        }

        return array_values(array_filter(
            $this->references,
            static fn(ReferenceOccurrence $reference): bool => $type === $reference->type,
        ));
    }

    /**
     * @return list<ReferenceOccurrence>
     */
    public function unresolved(): array
    {
        return array_values(array_filter(
            $this->references,
            static fn(ReferenceOccurrence $reference): bool => ReferenceStatus::Resolved !== $reference->status,
        ));
    }

    /**
     * Returns the first occurrence at a span, optionally restricted to one
     * language-level reference family.
     */
    public function referenceAt(ByteSpan $span, ?ReferenceType $type = null): ?ReferenceOccurrence
    {
        foreach ($this->referencesAt($span) as $reference) {
            if (null === $type || $type === $reference->type) {
                return $reference;
            }
        }

        return null;
    }

    /**
     * @return list<ReferenceOccurrence>
     */
    public function referencesAt(ByteSpan $span): array
    {
        return $this->referencesBySpan[self::spanKey($span)] ?? [];
    }

    /**
     * @return list<ReferenceOccurrence>
     */
    public function incoming(ReferenceDefinition $definition): array
    {
        return $this->incomingByDefinition[spl_object_id($definition)] ?? [];
    }

    /**
     * Returns the rendered anchor carrier without changing definition identity.
     */
    public function anchorNode(ReferenceDefinition $definition): Node
    {
        return $this->anchorNodes[spl_object_id($definition)] ?? $definition->node;
    }

    public function displayLabel(ReferenceDefinition $definition): ?string
    {
        return $this->definitionDisplayLabels[spl_object_id($definition)] ?? null;
    }

    /**
     * Returns the reader-facing title of a target's semantic carrier.
     */
    public function targetTitle(ReferenceDefinition $definition): string
    {
        $node = $this->anchorNode($definition);

        if (!$node instanceof Section) {
            return $definition->name;
        }

        $nodes = $this->inlineNodes($node->title->text, false);

        return null === $nodes ? $node->title->text->text : $this->plainText($nodes);
    }

    public function target(string $name): ?ReferenceDefinition
    {
        return $this->targets[ReferenceName::normalize($name)] ?? null;
    }

    /**
     * Returns a target only when its name was declared explicitly.
     *
     * Implicit section-title targets are deliberately excluded. This is the
     * lookup required by Sphinx :ref: when autosectionlabel is not enabled.
     */
    public function explicitTarget(string $name): ?ReferenceDefinition
    {
        return $this->explicitTargets[ReferenceName::normalize($name)] ?? null;
    }

    /**
     * @return array<string, ReferenceDefinition>
     */
    public function explicitTargets(): array
    {
        return $this->explicitTargets;
    }

    /**
     * @return array<string, list<ReferenceDefinition>>
     */
    public function explicitDefinitions(): array
    {
        return $this->explicitDefinitions;
    }

    /**
     * @return list<ReferenceDefinition>
     */
    public function unusedDefinitions(): array
    {
        return array_values(array_filter(
            $this->definitions,
            fn(ReferenceDefinition $definition): bool => [] === $this->incoming($definition),
        ));
    }

    public function problems(): ProblemReport
    {
        return $this->problems;
    }

    /**
     * Whether every source region that may contain references was analyzed.
     *
     * Opaque directive bodies make this false. Positive conclusions such as
     * "unused definition" must not be drawn from an incomplete graph.
     */
    public function isCoverageComplete(): bool
    {
        return $this->coverageComplete;
    }

    /**
     * Reuses the exact inline pass that built the graph.
     *
     * @return list<Node>|null
     */
    public function inlineNodes(Text $text, bool $perSegment): ?array
    {
        return $this->inlineNodes[self::inlineKey($text, $perSegment)] ?? null;
    }

    /**
     * Reuses inline nodes parsed from a legacy opaque directive body.
     * Structured block bodies expose their Text descendants through
     * inlineNodes() instead.
     *
     * @return list<Node>|null
     */
    public function directiveInlineNodes(Directive $directive): ?array
    {
        return $this->directiveInlineNodes[self::directiveInlineKey($directive)] ?? null;
    }

    public static function spanKey(ByteSpan $span): string
    {
        return $span->start . ':' . $span->length;
    }

    public static function inlineKey(Text $text, bool $perSegment): string
    {
        return ($perSegment ? 'segments:' : 'contiguous:') . self::spanKey($text->span());
    }

    public static function directiveInlineKey(Directive $directive): string
    {
        return 'directive:' . self::spanKey($directive->span());
    }

    private static function definitionKey(ReferenceDefinition $definition): string
    {
        $family = match ($definition->kind) {
            DefinitionKind::Footnote => ReferenceType::Footnote->value,
            DefinitionKind::Citation => ReferenceType::Citation->value,
            DefinitionKind::Substitution => ReferenceType::Substitution->value,
            default => ReferenceType::Hyperlink->value,
        };

        return $family . ':' . $definition->normalizedName;
    }

    private static function referenceKey(ReferenceOccurrence $reference): string
    {
        $family = match ($reference->type) {
            ReferenceType::SphinxRef => ReferenceType::Hyperlink->value,
            default => $reference->type->value,
        };

        return $family . ':' . $reference->normalizedLabel;
    }

    /**
     * @param list<Node> $nodes
     */
    private function plainText(array $nodes): string
    {
        $text = '';

        foreach ($nodes as $node) {
            $text .= match (true) {
                $node instanceof InlineText,
                $node instanceof InlineLiteral,
                $node instanceof InterpretedText => $node->text,
                $node instanceof HyperlinkReference => $node->text,
                $node instanceof StandaloneHyperlink => $node->uri,
                $node instanceof InlineTarget => $node->name,
                $node instanceof FootnoteReference,
                $node instanceof CitationReference => '[' . $node->label . ']',
                $node instanceof SubstitutionReference => $this->substitutionText($node),
                $node instanceof Emphasis,
                $node instanceof Strong => $this->plainText($node->children()),
                default => '',
            };
        }

        return $text;
    }

    private function substitutionText(SubstitutionReference $node): string
    {
        $reference = $this->referenceAt($node->span(), ReferenceType::Substitution);

        if (null === $reference || null === $reference->target || null === $reference->target->destination) {
            return '|' . $node->name . '|';
        }

        return $reference->target->destination;
    }
}
