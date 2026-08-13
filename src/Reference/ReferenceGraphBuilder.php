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

use Alto\Rst\Node\CitationDefinition;
use Alto\Rst\Node\ContainerNode;
use Alto\Rst\Node\DefinitionListItem;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\DirectiveBodyKind;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\FootnoteDefinition;
use Alto\Rst\Node\HyperlinkTarget;
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
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Section;
use Alto\Rst\Node\SubstitutionDefinition;
use Alto\Rst\Node\Table;
use Alto\Rst\Node\Text;
use Alto\Rst\Parser\InlineParser;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Source\ByteSpan;

/**
 * Mutable two-pass builder behind ReferenceGraph.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ReferenceGraphBuilder
{
    private const int MAX_SUBSTITUTION_DEPTH = 256;

    private const int MAX_SUBSTITUTION_BYTES = 1_048_576;

    /**
     * Legacy manually constructed directives that can still be analyzed as
     * inline prose when they do not carry typed block children.
     */
    private const array INLINE_BODY_DIRECTIVES = [
        'attention',
        'caution',
        'danger',
        'error',
        'hint',
        'important',
        'note',
        'tip',
        'warning',
    ];

    private readonly ProblemCollector $problems;

    private readonly InlineParser $inlineParser;

    /**
     * @var list<ReferenceDefinition>
     */
    private array $definitions = [];

    /**
     * @var array<string, list<ReferenceDefinition>>
     */
    private array $explicit = [];

    /**
     * @var array<string, list<ReferenceDefinition>>
     */
    private array $implicit = [];

    /**
     * @var array<int, string>
     */
    private array $aliases = [];

    /**
     * @var array<int, ReferenceDefinition>
     */
    private array $direct = [];

    /**
     * @var array<int, Node>
     */
    private array $anchorNodes = [];

    /**
     * @var list<ReferenceDefinition>
     */
    private array $anonymousDefinitions = [];

    /**
     * @var list<array{ReferenceType, string, ByteSpan, ?string}>
     */
    private array $rawReferences = [];

    /**
     * @var list<array{string, ByteSpan}>
     */
    private array $rawAnonymousReferences = [];

    /**
     * @var array<string, list<ReferenceDefinition>>
     */
    private array $typedDefinitions = [];

    /**
     * @var list<ReferenceDefinition>
     */
    private array $autoFootnotes = [];

    /**
     * @var list<ReferenceDefinition>
     */
    private array $symbolFootnotes = [];

    /**
     * @var array<int, string>
     */
    private array $footnoteDisplays = [];

    private int $autoFootnoteCursor = 0;

    private int $symbolFootnoteCursor = 0;

    /**
     * @var array<string, ReferenceDefinition>
     */
    private array $effectiveTargets = [];

    /**
     * @var array<string, ReferenceStatus>
     */
    private array $targetStatuses = [];

    /**
     * @var array<string, ReferenceDefinition|null>
     */
    private array $resolutionCache = [];

    /**
     * @var array<string, ReferenceStatus>
     */
    private array $resolutionStatuses = [];

    /**
     * @var list<ReferenceOccurrence>
     */
    private array $references = [];

    /**
     * @var array<string, list<ReferenceOccurrence>>
     */
    private array $referencesBySpan = [];

    /**
     * @var array<int, array{ReferenceStatus, ?ReferenceDefinition, ?string}>
     */
    private array $substitutionResolutionCache = [];

    /**
     * @var array<int, true>
     */
    private array $substitutionLimitProblems = [];

    /**
     * @var array<string, list<Node>>
     */
    private array $inlineNodes = [];

    /**
     * @var array<string, list<Node>>
     */
    private array $directiveInlineNodes = [];

    private bool $coverageComplete = true;

    public function __construct(
        private readonly string $source,
        private readonly ?Profile $profile,
    ) {
        $this->problems = new ProblemCollector();
        $this->inlineParser = new InlineParser($this->problems);
    }

    public function build(Document $document): ReferenceGraph
    {
        $this->collectBlocks($document->children());
        $this->assignFootnoteDisplays();
        $this->selectEffectiveTargets();
        $this->finalizeEffectiveTargets();
        $this->resolveReferences();
        $explicitTargets = [];

        foreach (array_keys($this->explicit) as $name) {
            if (isset($this->effectiveTargets[$name])) {
                $explicitTargets[$name] = $this->effectiveTargets[$name];
            }
        }

        return new ReferenceGraph(
            $this->definitions,
            $this->references,
            $this->effectiveTargets,
            $explicitTargets,
            $this->explicit,
            $this->referencesBySpan,
            $this->inlineNodes,
            $this->anchorNodes,
            $this->footnoteDisplays,
            $this->problems->report(),
            $this->coverageComplete,
            $this->directiveInlineNodes,
        );
    }

    /**
     * @param list<Node> $nodes
     */
    private function collectBlocks(array $nodes): void
    {
        /** @var list<ReferenceDefinition> $pending */
        $pending = [];
        /** @var list<int> $pendingAnonymous */
        $pendingAnonymous = [];

        foreach ($nodes as $node) {
            if ($node instanceof HyperlinkTarget) {
                if ('' === $node->target) {
                    $definition = $this->hyperlinkDefinition($node, null);

                    if ($node->anonymous) {
                        $this->anonymousDefinitions[] = $definition;
                        $pendingAnonymous[] = \count($this->anonymousDefinitions) - 1;
                    } else {
                        $this->registerExplicit($definition);
                        $pending[] = $definition;
                    }

                    continue;
                }

                $definition = $this->hyperlinkDefinition($node, $node->target);

                if ($node->anonymous) {
                    $this->anonymousDefinitions[] = $definition;
                } else {
                    $this->registerExplicit($definition);
                }

                foreach ($pending as $waiting) {
                    $this->direct[spl_object_id($waiting)] = $definition;
                }

                foreach ($pendingAnonymous as $index) {
                    $this->direct[spl_object_id($this->anonymousDefinitions[$index])] = $definition;
                }

                $pending = [];
                $pendingAnonymous = [];

                continue;
            }

            if ([] !== $pending || [] !== $pendingAnonymous) {
                foreach ($pending as $waiting) {
                    $this->anchorNodes[spl_object_id($waiting)] = $node;
                }

                foreach ($pendingAnonymous as $index) {
                    $definition = $this->anonymousDefinitions[$index];
                    $this->anchorNodes[spl_object_id($definition)] = $node;
                }

                $pending = [];
                $pendingAnonymous = [];
                $this->collectNode($node, false);

                continue;
            }

            $this->collectNode($node, false);
        }
    }

    private function collectNode(Node $node, bool $sectionAlreadyRegistered): void
    {
        if ($node instanceof Section) {
            $titleNodes = $this->collectText($node->title->text, false);

            if (!$sectionAlreadyRegistered) {
                $name = $this->inlinePlainText($titleNodes) ?? $node->title->text->text;
                $definition = new ReferenceDefinition(
                    DefinitionKind::Section,
                    $name,
                    ReferenceName::normalize($name),
                    $node->title->span(),
                    $node,
                );
                $this->definitions[] = $definition;
                $this->registerImplicit($definition);
            }

            $this->collectBlocks($node->body());

            return;
        }

        if ($node instanceof Paragraph) {
            $this->collectText($node->text, false);

            return;
        }

        if ($node instanceof DefinitionListItem) {
            $this->collectText($node->term, false);

            foreach ($node->classifiers as $classifier) {
                $this->collectText($classifier, false);
            }

            $this->collectBlocks($node->definition());

            return;
        }

        if ($node instanceof Directive) {
            if (DirectiveBodyKind::Blocks === $node->bodyKind) {
                $this->collectBlocks($node->children());
            } elseif (
                DirectiveBodyKind::Opaque === $node->bodyKind
                || (DirectiveBodyKind::None === $node->bodyKind && null !== $node->rawBody)
            ) {
                $this->collectDirectiveBody($node);
            }

            return;
        }

        if ($node instanceof Table) {
            foreach ($node->children() as $row) {
                foreach ($row->children() as $cell) {
                    foreach ($cell->children() as $child) {
                        if ($child instanceof Paragraph) {
                            $this->collectText($child->text, true);
                        } else {
                            $this->collectNode($child, false);
                        }
                    }
                }
            }

            return;
        }

        if ($node instanceof FootnoteDefinition) {
            $definition = new ReferenceDefinition(
                DefinitionKind::Footnote,
                $node->label,
                ReferenceName::normalize($node->label),
                $node->span(),
                $node,
            );
            $this->definitions[] = $definition;
            $this->registerTyped(ReferenceType::Footnote, $definition);
            $this->collectBlocks($node->children());

            return;
        }

        if ($node instanceof CitationDefinition) {
            $definition = new ReferenceDefinition(
                DefinitionKind::Citation,
                $node->label,
                ReferenceName::normalize($node->label),
                $node->span(),
                $node,
            );
            $this->definitions[] = $definition;
            $this->registerTyped(ReferenceType::Citation, $definition);
            $this->collectBlocks($node->children());

            return;
        }

        if ($node instanceof SubstitutionDefinition) {
            [$kind, $destination, $destinationSpan, $alt] = $this->substitutionValue($node);
            $definition = new ReferenceDefinition(
                DefinitionKind::Substitution,
                $node->name,
                ReferenceName::normalize($node->name),
                $node->span(),
                $node,
                $destination,
                $destinationSpan,
                $kind,
                $alt,
            );
            $this->definitions[] = $definition;
            $this->registerTyped(ReferenceType::Substitution, $definition);

            if (SubstitutionKind::Replace === $kind && null !== $definition->destination) {
                $this->collectInline(
                    $this->inlineParser->parse(
                        $definition->destination,
                        null === $definition->destinationSpan
                            ? $node->span()->start
                            : $definition->destinationSpan->start,
                    ),
                );
            }

            return;
        }

        if ($node instanceof ContainerNode) {
            $this->collectBlocks($node->children());
        }
    }

    private function collectDirectiveBody(Directive $directive): void
    {
        if (null === $directive->rawBody) {
            return;
        }

        $body = substr($this->source, $directive->rawBody->start, $directive->rawBody->length);
        $contentOffset = strspn($body, " \t");
        $content = substr($body, $contentOffset);

        if (!$this->isSimpleInlineDirectiveBody($directive, $body, $contentOffset)) {
            $this->coverageComplete = false;

            return;
        }

        $nodes = $this->inlineParser->parse(
            $content,
            $directive->rawBody->start + $contentOffset,
        );
        $this->directiveInlineNodes[ReferenceGraph::directiveInlineKey($directive)] = $nodes;
        $this->collectInline($nodes);
    }

    private function isSimpleInlineDirectiveBody(
        Directive $directive,
        string $body,
        int $contentOffset,
    ): bool {
        if (
            !\in_array(strtolower($directive->name), self::INLINE_BODY_DIRECTIVES, true)
            || '' === $body
        ) {
            return false;
        }

        /*
         * Uniformly indented prose can be passed to InlineParser with its
         * original newlines and byte offsets. Extra indentation or block
         * syntax stays opaque so nested directives, lists, fields, tables, and
         * literal blocks are never interpreted as inline prose.
         */
        $lines = preg_split('/\r\n|\r|\n/', $body);

        if (false === $lines) {
            return false;
        }

        foreach ($lines as $line) {
            if ('' === trim($line, " \t\v\f")) {
                continue;
            }

            $indent = strspn($line, " \t");

            if ($indent !== $contentOffset) {
                return false;
            }

            $content = substr($line, $indent);

            if (1 === preg_match(
                '/^(?:\.\.(?:\s|$)|[-+*]\s|(?:\d+|#)[.)]\s|:[^:]+:\s|\|\s|::(?:\s|$)|[-=~`^"\'+#*_:<>]{4,}\s*$)/',
                $content,
            )) {
                return false;
            }
        }

        return true;
    }

    private function hyperlinkDefinition(HyperlinkTarget $node, ?string $destination): ReferenceDefinition
    {
        $definition = new ReferenceDefinition(
            DefinitionKind::Hyperlink,
            $node->name,
            ReferenceName::normalize($node->name),
            $node->span(),
            $node,
            null === $destination || null !== $this->aliasName($destination) ? null : $destination,
        );
        $this->definitions[] = $definition;

        if (null !== $destination && null !== ($alias = $this->aliasName($destination))) {
            $this->aliases[spl_object_id($definition)] = ReferenceName::normalize($alias);
        }

        return $definition;
    }

    private function registerExplicit(ReferenceDefinition $definition): void
    {
        $this->explicit[$definition->normalizedName][] = $definition;
    }

    private function registerImplicit(ReferenceDefinition $definition): void
    {
        $this->implicit[$definition->normalizedName][] = $definition;
    }

    private function registerTyped(ReferenceType $type, ReferenceDefinition $definition): void
    {
        if (ReferenceType::Footnote === $type && '#' === $definition->name) {
            $this->autoFootnotes[] = $definition;

            return;
        }

        if (ReferenceType::Footnote === $type && '*' === $definition->name) {
            $this->symbolFootnotes[] = $definition;

            return;
        }

        $key = $type->value . ':' . $definition->normalizedName;

        if (isset($this->typedDefinitions[$key])) {
            $this->problem(
                ProblemSeverity::Error,
                $type->value . '/duplicate-definition',
                \sprintf('%s definition "%s" is defined more than once.', ucfirst($type->value), $definition->name),
                $definition->span,
            );
        }

        $this->typedDefinitions[$key][] = $definition;
    }

    private function assignFootnoteDisplays(): void
    {
        $reserved = [];

        foreach ($this->definitions as $definition) {
            if (DefinitionKind::Footnote === $definition->kind && 1 === preg_match('/^[0-9]+$/', $definition->name)) {
                $reserved[(int) $definition->name] = true;
                $this->footnoteDisplays[spl_object_id($definition)] = $definition->name;
            }
        }

        $next = 1;
        $symbol = 0;

        foreach ($this->definitions as $definition) {
            if (DefinitionKind::Footnote !== $definition->kind) {
                continue;
            }

            if ('*' === $definition->name) {
                $this->footnoteDisplays[spl_object_id($definition)] = $this->footnoteSymbol($symbol++);

                continue;
            }

            if (!str_starts_with($definition->name, '#')) {
                continue;
            }

            while (isset($reserved[$next])) {
                ++$next;
            }

            $this->footnoteDisplays[spl_object_id($definition)] = (string) $next;
            $reserved[$next] = true;
            ++$next;
        }
    }

    /**
     * @return list<Node>
     */
    private function collectText(Text $text, bool $perSegment): array
    {
        if (!$perSegment || !str_contains($text->text, "\n")) {
            $nodes = $this->inlineParser->parse($text->text, $text->span()->start);
            $this->inlineNodes[ReferenceGraph::inlineKey($text, $perSegment)] = $nodes;
            $this->collectInline($nodes);

            return $nodes;
        }

        $segments = explode("\n", $text->text);
        $sourceSegments = $text->sourceSegments();
        $offset = $text->span()->start;
        $nodes = [];

        foreach ($segments as $index => $segment) {
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

        $this->inlineNodes[ReferenceGraph::inlineKey($text, true)] = $nodes;
        $this->collectInline($nodes);

        return $nodes;
    }

    /**
     * Returns visible title text for inline forms whose rendering is local.
     * Substitutions remain deferred, so a title containing one keeps its raw
     * source name until substitution expansion becomes part of graph build.
     *
     * @param list<Node> $nodes
     */
    private function inlinePlainText(array $nodes): ?string
    {
        $text = '';

        foreach ($nodes as $node) {
            $part = match (true) {
                $node instanceof InlineText,
                $node instanceof InlineLiteral,
                $node instanceof InterpretedText => $node->text,
                $node instanceof HyperlinkReference => $node->text,
                $node instanceof StandaloneHyperlink => $node->uri,
                $node instanceof InlineTarget => $node->name,
                $node instanceof FootnoteReference,
                $node instanceof CitationReference => '[' . $node->label . ']',
                $node instanceof Emphasis,
                $node instanceof Strong => $this->inlinePlainText($node->children()),
                default => null,
            };

            if (null === $part) {
                return null;
            }

            $text .= $part;
        }

        return $text;
    }

    /**
     * @param list<Node> $nodes
     */
    private function collectInline(array $nodes): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof HyperlinkReference) {
                if (null !== $node->embeddedUri && null === $this->aliasName($node->embeddedUri)) {
                    $definition = new ReferenceDefinition(
                        DefinitionKind::Hyperlink,
                        $node->text,
                        ReferenceName::normalize($node->text),
                        $node->span(),
                        $node,
                        $node->embeddedUri,
                    );
                    $this->appendReference(new ReferenceOccurrence(
                        ReferenceType::Hyperlink,
                        $node->text,
                        ReferenceName::normalize($node->text),
                        $node->span(),
                        null,
                        ReferenceStatus::Resolved,
                        $definition,
                    ));
                } elseif ($node->anonymous) {
                    $this->rawAnonymousReferences[] = [$node->text, $node->span()];
                } else {
                    $label = null === $node->embeddedUri ? $node->text : (string) $this->aliasName($node->embeddedUri);
                    $this->rawReferences[] = [ReferenceType::Hyperlink, $label, $node->span(), null];
                }
            } elseif ($node instanceof FootnoteReference) {
                $this->rawReferences[] = [ReferenceType::Footnote, $node->label, $node->span(), null];
            } elseif ($node instanceof CitationReference) {
                $this->rawReferences[] = [ReferenceType::Citation, $node->label, $node->span(), null];
            } elseif ($node instanceof SubstitutionReference) {
                $this->rawReferences[] = [ReferenceType::Substitution, $node->name, $node->span(), null];

                if ($node->reference) {
                    if ($node->anonymous) {
                        $this->rawAnonymousReferences[] = [$node->name, $node->span()];
                    } else {
                        $this->rawReferences[] = [ReferenceType::Hyperlink, $node->name, $node->span(), null];
                    }
                }
            } elseif ($node instanceof InterpretedText) {
                $this->collectRoleReference($node);
            } elseif ($node instanceof InlineTarget) {
                $definition = new ReferenceDefinition(
                    DefinitionKind::InlineTarget,
                    $node->name,
                    ReferenceName::normalize($node->name),
                    $node->span(),
                    $node,
                );
                $this->definitions[] = $definition;
                $this->registerExplicit($definition);
            } elseif ($node instanceof Emphasis || $node instanceof Strong) {
                $this->collectInline($node->children());
            }
        }
    }

    private function collectRoleReference(InterpretedText $node): void
    {
        if (null === $node->role || null === $this->profile) {
            return;
        }

        $role = $this->profile->roles->get($node->role)?->name;

        if (!\in_array($role, ['ref', 'doc'], true)) {
            return;
        }

        [$title, $label] = $this->roleParts($node->text);
        $type = 'ref' === $role ? ReferenceType::SphinxRef : ReferenceType::SphinxDoc;
        $this->rawReferences[] = [$type, $label, $node->span(), $title];
    }

    /**
     * @return array{?string, string}
     */
    private function roleParts(string $text): array
    {
        if (1 === preg_match('/^(.*\S)\s*<([^<>]+)>$/s', $text, $matches)) {
            return [$matches[1], trim($matches[2])];
        }

        return [null, trim($text)];
    }

    private function selectEffectiveTargets(): void
    {
        $names = array_unique([...array_keys($this->implicit), ...array_keys($this->explicit)]);

        foreach ($names as $name) {
            $explicit = $this->explicit[$name] ?? [];
            $implicit = $this->implicit[$name] ?? [];

            if (\count($explicit) > 1) {
                $this->targetStatuses[$name] = ReferenceStatus::Ambiguous;

                foreach (\array_slice($explicit, 1) as $duplicate) {
                    $this->problem(
                        ProblemSeverity::Error,
                        'reference/duplicate-explicit-target',
                        \sprintf('Explicit target "%s" is defined more than once.', $duplicate->name),
                        $duplicate->span,
                    );
                }

                continue;
            }

            if (1 === \count($explicit)) {
                $this->effectiveTargets[$name] = $explicit[0];
                $this->targetStatuses[$name] = ReferenceStatus::Resolved;

                continue;
            }

            if (\count($implicit) > 1) {
                $this->targetStatuses[$name] = ReferenceStatus::Ambiguous;

                continue;
            }

            if (1 === \count($implicit)) {
                $this->effectiveTargets[$name] = $implicit[0];
                $this->targetStatuses[$name] = ReferenceStatus::Resolved;
            }
        }
    }

    private function finalizeEffectiveTargets(): void
    {
        $resolved = [];

        foreach (array_keys($this->effectiveTargets) as $name) {
            $definition = $this->effectiveTargets[$name];
            [$status, $target] = $this->resolveName($name);

            if (ReferenceStatus::Resolved === $status && null !== $target) {
                $resolved[$name] = $target;

                continue;
            }

            if (isset($this->aliases[spl_object_id($definition)])) {
                $suffix = ReferenceStatus::Circular === $status ? 'circular' : 'unresolved';
                $this->problem(
                    ProblemSeverity::Error,
                    'reference/' . $suffix . '-indirect-target',
                    \sprintf('Indirect target "%s" is %s.', $definition->name, $status->value),
                    $definition->span,
                );
            }
        }

        $this->effectiveTargets = $resolved;
    }

    private function resolveReferences(): void
    {
        foreach ($this->rawReferences as [$type, $label, $span, $title]) {
            $this->appendReference($this->resolveReference($type, $label, $span, $title));
        }

        if (\count($this->rawAnonymousReferences) !== \count($this->anonymousDefinitions)) {
            foreach ($this->rawAnonymousReferences as [$label, $span]) {
                $this->appendReference(new ReferenceOccurrence(
                    ReferenceType::Hyperlink,
                    $label,
                    '',
                    $span,
                    null,
                    ReferenceStatus::Unresolved,
                    null,
                ));
            }

            if ([] !== $this->rawAnonymousReferences || [] !== $this->anonymousDefinitions) {
                $span = $this->rawAnonymousReferences[0][1] ?? $this->anonymousDefinitions[0]->span;
                $this->problem(
                    ProblemSeverity::Error,
                    'reference/anonymous-mismatch',
                    'Anonymous references and targets must have equal counts.',
                    $span,
                );
            }

            return;
        }

        foreach ($this->rawAnonymousReferences as $index => [$label, $span]) {
            [$status, $target] = $this->resolveDefinition($this->anonymousDefinitions[$index]);
            $this->appendReference(new ReferenceOccurrence(
                ReferenceType::Hyperlink,
                $label,
                '',
                $span,
                null,
                $status,
                $target,
            ));
        }
    }

    private function resolveReference(
        ReferenceType $type,
        string $label,
        ByteSpan $span,
        ?string $title,
    ): ReferenceOccurrence {
        $normalized = ReferenceName::normalize($label);

        if (ReferenceType::SphinxDoc === $type) {
            return new ReferenceOccurrence($type, $label, $normalized, $span, $title, ReferenceStatus::Deferred, null);
        }

        if (ReferenceType::Footnote === $type || ReferenceType::Citation === $type || ReferenceType::Substitution === $type) {
            [$status, $target, $display] = $this->resolveTyped($type, $label);
        } else {
            [$status, $target] = $this->resolveName($normalized);
            $display = null;

            if (ReferenceType::SphinxRef === $type && ReferenceStatus::Unresolved === $status) {
                $status = ReferenceStatus::Deferred;
            }
        }

        if (\in_array($status, [ReferenceStatus::Unresolved, ReferenceStatus::Ambiguous, ReferenceStatus::Circular], true)) {
            $this->problemForResolution($type, $label, $span, $status);
        }

        return new ReferenceOccurrence($type, $label, $normalized, $span, $title, $status, $target, $display);
    }

    /**
     * @return array{ReferenceStatus, ?ReferenceDefinition}
     */
    private function resolveName(string $name): array
    {
        $current = $name;
        $path = [];
        $seenNames = [];
        $status = ReferenceStatus::Unresolved;
        $target = null;

        while (true) {
            if (isset($this->resolutionStatuses[$current])) {
                $status = $this->resolutionStatuses[$current];
                $target = $this->resolutionCache[$current] ?? null;

                break;
            }

            if (isset($seenNames[$current])) {
                $status = ReferenceStatus::Circular;

                break;
            }

            $seenNames[$current] = true;
            $path[] = $current;
            $status = $this->targetStatuses[$current] ?? ReferenceStatus::Unresolved;

            if (ReferenceStatus::Resolved !== $status) {
                break;
            }

            $definition = $this->effectiveTargets[$current];
            $definitionIds = [];

            while (isset($this->direct[spl_object_id($definition)])) {
                $id = spl_object_id($definition);

                if (isset($definitionIds[$id])) {
                    $status = ReferenceStatus::Circular;

                    break 2;
                }

                $definitionIds[$id] = true;
                $definition = $this->direct[$id];
            }

            $alias = $this->aliases[spl_object_id($definition)] ?? null;

            if (null !== $alias) {
                $current = $alias;

                continue;
            }

            $target = $definition;

            break;
        }

        foreach ($path as $resolvedName) {
            $this->resolutionStatuses[$resolvedName] = $status;
            $this->resolutionCache[$resolvedName] = $target;
        }

        return [$status, $target];
    }

    /**
     * @return array{ReferenceStatus, ?ReferenceDefinition}
     */
    private function resolveDefinition(ReferenceDefinition $definition): array
    {
        $seen = [];

        while (isset($this->direct[spl_object_id($definition)])) {
            $id = spl_object_id($definition);

            if (isset($seen[$id])) {
                return [ReferenceStatus::Circular, null];
            }

            $seen[$id] = true;
            $definition = $this->direct[$id];
        }

        $alias = $this->aliases[spl_object_id($definition)] ?? null;

        if (null !== $alias) {
            return $this->resolveName($alias);
        }

        return [ReferenceStatus::Resolved, $definition];
    }

    /**
     * @return array{ReferenceStatus, ?ReferenceDefinition, ?string}
     */
    private function resolveTyped(ReferenceType $type, string $label): array
    {
        if (ReferenceType::Footnote === $type && '#' === $label) {
            $definition = $this->autoFootnotes[$this->autoFootnoteCursor++] ?? null;

            return null === $definition
                ? [ReferenceStatus::Unresolved, null, null]
                : [ReferenceStatus::Resolved, $definition, $this->footnoteDisplays[spl_object_id($definition)]];
        }

        if (ReferenceType::Footnote === $type && '*' === $label) {
            $definition = $this->symbolFootnotes[$this->symbolFootnoteCursor++] ?? null;

            return null === $definition
                ? [ReferenceStatus::Unresolved, null, null]
                : [ReferenceStatus::Resolved, $definition, $this->footnoteDisplays[spl_object_id($definition)]];
        }

        $normalized = ReferenceName::normalize($label);
        $key = $type->value . ':' . $normalized;
        $definitions = $this->typedDefinitions[$key] ?? [];

        if ([] === $definitions) {
            return [ReferenceStatus::Unresolved, null, null];
        }

        if (\count($definitions) > 1) {
            if (ReferenceType::Substitution === $type) {
                return $this->resolveSubstitution($definitions[\count($definitions) - 1], []);
            }

            return [ReferenceStatus::Ambiguous, null, null];
        }

        if (ReferenceType::Substitution === $type) {
            return $this->resolveSubstitution($definitions[0], []);
        }

        return [ReferenceStatus::Resolved, $definitions[0], $this->displayLabel($type, $definitions[0])];
    }

    private function displayLabel(ReferenceType $type, ReferenceDefinition $definition): ?string
    {
        if (ReferenceType::Footnote !== $type) {
            return null;
        }

        return $this->footnoteDisplays[spl_object_id($definition)] ?? $definition->name;
    }

    /**
     * @param array<string, true> $seen
     *
     * @return array{ReferenceStatus, ?ReferenceDefinition, ?string}
     */
    private function resolveSubstitution(ReferenceDefinition $definition, array $seen): array
    {
        $id = spl_object_id($definition);

        if (isset($this->substitutionResolutionCache[$id])) {
            return $this->substitutionResolutionCache[$id];
        }

        $name = $definition->normalizedName;

        if (\count($seen) >= self::MAX_SUBSTITUTION_DEPTH) {
            $this->substitutionLimitProblem($definition);

            return $this->substitutionResolutionCache[$id] = [ReferenceStatus::Unresolved, null, null];
        }

        if (isset($seen[$name])) {
            return [ReferenceStatus::Circular, null, null];
        }

        $seen[$name] = true;
        $replacement = $definition->destination ?? '';
        $status = ReferenceStatus::Resolved;
        $expanded = '';
        $cursor = 0;
        $nodes = $this->substitutionReferences($this->inlineParser->parse($replacement, 0));

        foreach ($nodes as $node) {
            $start = $node->span()->start;
            $expanded .= substr($replacement, $cursor, $start - $cursor);
            $key = ReferenceType::Substitution->value . ':' . ReferenceName::normalize($node->name);
            $definitions = $this->typedDefinitions[$key] ?? [];

            if ([] === $definitions) {
                $status = ReferenceStatus::Unresolved;
                $expanded .= substr($replacement, $start, $node->span()->length);
                $cursor = $node->span()->end();

                continue;
            }

            $nested = $definitions[\count($definitions) - 1];
            [$nestedStatus, $target] = $this->resolveSubstitution($nested, $seen);

            if (ReferenceStatus::Resolved !== $nestedStatus || null === $target) {
                $status = $nestedStatus;
                $expanded .= substr($replacement, $start, $node->span()->length);
                $cursor = $node->span()->end();

                continue;
            }

            $expanded .= $target->destination ?? '';

            if ($node->reference) {
                $expanded .= $node->anonymous ? '__' : '_';
            }

            if (\strlen($expanded) > self::MAX_SUBSTITUTION_BYTES) {
                $this->substitutionLimitProblem($definition);

                return $this->substitutionResolutionCache[$id] = [ReferenceStatus::Unresolved, null, null];
            }

            $cursor = $node->span()->end();
        }

        $expanded .= substr($replacement, $cursor);

        if (\strlen($expanded) > self::MAX_SUBSTITUTION_BYTES) {
            $this->substitutionLimitProblem($definition);

            return $this->substitutionResolutionCache[$id] = [ReferenceStatus::Unresolved, null, null];
        }

        if (ReferenceStatus::Resolved !== $status) {
            return $this->substitutionResolutionCache[$id] = [$status, null, null];
        }

        return $this->substitutionResolutionCache[$id] = [
            ReferenceStatus::Resolved,
            new ReferenceDefinition(
                $definition->kind,
                $definition->name,
                $definition->normalizedName,
                $definition->span,
                $definition->node,
                $expanded,
                $definition->destinationSpan,
                $definition->substitutionKind,
                $definition->substitutionAlt,
            ),
            null,
        ];
    }

    private function footnoteSymbol(int $index): string
    {
        $symbols = [
            '*',
            "\u{2020}",
            "\u{2021}",
            "\u{00A7}",
            "\u{00B6}",
            '#',
            "\u{2660}",
            "\u{2665}",
            "\u{2666}",
            "\u{2663}",
        ];
        $symbol = $symbols[$index % \count($symbols)];

        return str_repeat($symbol, intdiv($index, \count($symbols)) + 1);
    }

    /**
     * @return array{?SubstitutionKind, ?string, ?ByteSpan, ?string}
     */
    private function substitutionValue(SubstitutionDefinition $definition): array
    {
        $directive = $definition->directive;
        $argument = $directive->arguments[0] ?? '';
        $span = '' === $argument ? $directive->rawBody : $this->substitutionDestinationSpan($definition, $argument);

        if ('replace' === $directive->name) {
            $parts = [];

            if ('' !== $argument) {
                $parts[] = $argument;
            }

            $body = $this->substitutionBody($directive->rawBody);

            if ('' !== $body) {
                $parts[] = $body;
            }

            return [SubstitutionKind::Replace, implode("\n", $parts), $span, null];
        }

        if ('unicode' === $directive->name) {
            $value = $this->decodeUnicode($argument);

            if (null === $value) {
                $this->problem(
                    ProblemSeverity::Error,
                    'substitution/invalid-unicode',
                    \sprintf('Unicode substitution "%s" has an invalid code point.', $definition->name),
                    $definition->span(),
                );

                return [SubstitutionKind::Unicode, null, $span, null];
            }

            if (\array_key_exists('trim', $directive->options)) {
                $value = trim($value);
            } elseif (\array_key_exists('ltrim', $directive->options)) {
                $value = ltrim($value);
            } elseif (\array_key_exists('rtrim', $directive->options)) {
                $value = rtrim($value);
            }

            return [SubstitutionKind::Unicode, $value, $span, null];
        }

        if ('image' === $directive->name && '' !== $argument) {
            return [SubstitutionKind::Image, $argument, $span, $directive->options['alt'] ?? $argument];
        }

        $this->problem(
            ProblemSeverity::Error,
            'substitution/unsupported-directive',
            \sprintf('Substitution "%s" uses unsupported directive "%s".', $definition->name, $directive->name),
            $definition->span(),
        );

        return [null, null, $span, null];
    }

    private function substitutionBody(?ByteSpan $span): string
    {
        if (null === $span) {
            return '';
        }

        $body = substr($this->source, $span->start, $span->length);
        $lines = preg_split('/\R/', $body);

        if (false === $lines) {
            return trim($body);
        }

        $indent = \PHP_INT_MAX;

        foreach ($lines as $line) {
            if ('' !== trim($line)) {
                $indent = min($indent, \strlen($line) - \strlen(ltrim($line, " \t")));
            }
        }

        if (\PHP_INT_MAX !== $indent && $indent > 0) {
            foreach ($lines as $index => $line) {
                $lines[$index] = substr($line, min($indent, \strlen($line)));
            }
        }

        return trim(implode("\n", $lines));
    }

    private function decodeUnicode(string $argument): ?string
    {
        $tokens = preg_split('/\s+/', trim($argument));

        if (false === $tokens || [] === $tokens) {
            return null;
        }

        $value = '';

        foreach ($tokens as $token) {
            $codePoint = null;

            if (1 === preg_match('/^(?:0x|x|u\+)([0-9a-f]+)$/i', $token, $matches)
                || 1 === preg_match('/^&#x([0-9a-f]+);$/i', $token, $matches)
            ) {
                $codePoint = intval($matches[1], 16);
            } elseif (1 === preg_match('/^&#([0-9]+);$/', $token, $matches)
                || 1 === preg_match('/^([0-9]+)$/', $token, $matches)
            ) {
                $codePoint = (int) $matches[1];
            }

            if (null === $codePoint || $codePoint < 0 || $codePoint > 0x10FFFF
                || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)
            ) {
                return null;
            }

            $value .= self::utf8CodePoint($codePoint);
        }

        return $value;
    }

    private static function utf8CodePoint(int $codePoint): string
    {
        if ($codePoint <= 0x7F) {
            return pack('C', $codePoint);
        }

        if ($codePoint <= 0x7FF) {
            return pack('C', 0xC0 | ($codePoint >> 6))
                . pack('C', 0x80 | ($codePoint & 0x3F));
        }

        if ($codePoint <= 0xFFFF) {
            return pack('C', 0xE0 | ($codePoint >> 12))
                . pack('C', 0x80 | (($codePoint >> 6) & 0x3F))
                . pack('C', 0x80 | ($codePoint & 0x3F));
        }

        return pack('C', 0xF0 | ($codePoint >> 18))
            . pack('C', 0x80 | (($codePoint >> 12) & 0x3F))
            . pack('C', 0x80 | (($codePoint >> 6) & 0x3F))
            . pack('C', 0x80 | ($codePoint & 0x3F));
    }

    /**
     * @param list<Node> $nodes
     *
     * @return list<SubstitutionReference>
     */
    private function substitutionReferences(array $nodes): array
    {
        $references = [];

        foreach ($nodes as $node) {
            if ($node instanceof SubstitutionReference) {
                $references[] = $node;
            } elseif ($node instanceof Emphasis || $node instanceof Strong) {
                foreach ($this->substitutionReferences($node->children()) as $reference) {
                    $references[] = $reference;
                }
            }
        }

        usort(
            $references,
            static fn(SubstitutionReference $left, SubstitutionReference $right): int => $left->span()->start <=> $right->span()->start,
        );

        return $references;
    }

    private function substitutionLimitProblem(ReferenceDefinition $definition): void
    {
        $id = spl_object_id($definition);

        if (isset($this->substitutionLimitProblems[$id])) {
            return;
        }

        $this->substitutionLimitProblems[$id] = true;
        $this->problem(
            ProblemSeverity::Error,
            'substitution/expansion-limit',
            \sprintf(
                'Substitution "%s" exceeds the expansion limit of %d bytes or %d levels.',
                $definition->name,
                self::MAX_SUBSTITUTION_BYTES,
                self::MAX_SUBSTITUTION_DEPTH,
            ),
            $definition->span,
        );
    }

    private function substitutionDestinationSpan(SubstitutionDefinition $definition, string $argument): ?ByteSpan
    {
        $start = $definition->directive->span()->start
            + \strlen($definition->directive->name)
            + 2;
        $position = strpos($this->source, $argument, $start);

        if (false === $position || $position + \strlen($argument) > $definition->directive->span()->end()) {
            return null;
        }

        return ByteSpan::of($position, \strlen($argument));
    }

    private function appendReference(ReferenceOccurrence $reference): void
    {
        $this->references[] = $reference;
        $this->referencesBySpan[ReferenceGraph::spanKey($reference->span)][] = $reference;
    }

    private function problemForResolution(
        ReferenceType $type,
        string $label,
        ByteSpan $span,
        ReferenceStatus $status,
    ): void {
        $area = match ($type) {
            ReferenceType::Footnote => 'footnote',
            ReferenceType::Citation => 'citation',
            ReferenceType::Substitution => 'substitution',
            default => 'reference',
        };
        $suffix = match ($status) {
            ReferenceStatus::Ambiguous => 'ambiguous-target',
            ReferenceStatus::Circular => 'circular-target',
            default => 'unresolved-target',
        };
        $this->problem(
            ProblemSeverity::Error,
            $area . '/' . $suffix,
            \sprintf('%s reference "%s" is %s.', ucfirst($type->value), $label, $status->value),
            $span,
        );
    }

    private function problem(ProblemSeverity $severity, string $code, string $message, ByteSpan $span): void
    {
        $this->problems->add(new Problem($severity, $code, $message, $span));
    }

    private function aliasName(string $destination): ?string
    {
        if (1 === preg_match('/^`(.+)`_$/', $destination, $matches)) {
            return $matches[1];
        }

        if (str_contains($destination, '/') || str_contains($destination, ':')) {
            return null;
        }

        if (1 === preg_match('/^([A-Za-z0-9][A-Za-z0-9._+ -]*)_$/', $destination, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
