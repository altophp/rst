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

use Alto\Rst\Extension\DirectiveRenderContext;
use Alto\Rst\Node\BlockQuote;
use Alto\Rst\Node\BulletList;
use Alto\Rst\Node\CitationDefinition;
use Alto\Rst\Node\Comment;
use Alto\Rst\Node\ContainerNode;
use Alto\Rst\Node\DefinitionList;
use Alto\Rst\Node\DefinitionListItem;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\DirectiveBodyKind;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\EnumeratedList;
use Alto\Rst\Node\EnumerationStyle;
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
use Alto\Rst\Node\ListItem;
use Alto\Rst\Node\LiteralBlock;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Section;
use Alto\Rst\Node\SubstitutionDefinition;
use Alto\Rst\Node\Table;
use Alto\Rst\Node\TableRow;
use Alto\Rst\Node\Text;
use Alto\Rst\Node\Transition;
use Alto\Rst\Reference\ReferenceDefinition;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Reference\ReferenceOccurrence;
use Alto\Rst\Reference\ReferenceStatus;
use Alto\Rst\Reference\ReferenceType;
use Alto\Rst\Reference\SubstitutionKind;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;

/**
 * Renders the ROM to HTML, safe by default.
 *
 * The renderer consumes the node tree; the Source is required because
 * LiteralBlock content and Directive raw bodies are byte spans over the
 * original input, sliced through Source::slice(). Nothing else is read
 * back from the source bytes.
 *
 * Paragraph and title text goes through the inline pass at render time:
 * emphasis, strong, inline literals, and links become real elements. Link
 * destinations pass through HtmlPolicy::isUrlAllowed(); a disallowed URL
 * keeps its element with an empty href. Internal references resolve to
 * fragment anchors against the hyperlink targets and section ids
 * collected in a pre-pass; an unresolved reference stays plain text.
 *
 * Every text fragment is escaped; there is no raw HTML path. Unknown
 * constructs render as inert, inspectable HTML comments, never as raw
 * passthrough. Directive rendering beyond the base admonition set is
 * driven by the optional Profile in RenderOptions.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HtmlRenderer
{
    /**
     * The v0 admonition set, used when no profile is given.
     *
     * @var array<string, true>
     */
    private const array ADMONITIONS = [
        'note' => true,
        'tip' => true,
        'warning' => true,
        'caution' => true,
        'important' => true,
    ];

    /**
     * Admonition directive names and their displayed titles, applied when
     * the profile enables the directive.
     *
     * @var array<string, string>
     */
    private const array ADMONITION_TITLES = [
        'attention' => 'Attention',
        'caution' => 'Caution',
        'danger' => 'Danger',
        'error' => 'Error',
        'hint' => 'Hint',
        'important' => 'Important',
        'note' => 'Note',
        'tip' => 'Tip',
        'warning' => 'Warning',
        'seealso' => 'See also',
        'best-practice' => 'Best practice',
    ];

    /**
     * @var array<string, string>
     */
    private const array VERSION_PREFIXES = [
        'versionadded' => 'New in version',
        'versionchanged' => 'Changed in version',
        'deprecated' => 'Deprecated since version',
    ];

    /**
     * Interpreted-text roles rendered as semantic elements, keyed by the
     * role's canonical profile name.
     *
     * @var array<string, string>
     */
    private const array ROLE_ELEMENTS = [
        'emphasis' => 'em',
        'strong' => 'strong',
        'literal' => 'code',
        'code' => 'code',
        'subscript' => 'sub',
        'superscript' => 'sup',
        'title-reference' => 'cite',
    ];

    public function render(
        Document $document,
        Source $source,
        ?RenderOptions $options = null,
        ?ReferenceGraph $references = null,
    ): string {
        $options ??= new RenderOptions();
        $references ??= ReferenceGraph::fromDocument($document, $source, $options->profile);
        $state = new RenderState($source, $options->htmlPolicy, $options->profile, $references);

        $this->collect($document->children(), $state, false);
        $state->prepareReferenceIds();

        return $this->renderChildren($document->children(), $state, false);
    }

    /**
     * The pre-pass: walks the tree in document order, hands out section
     * and target ids through the shared registry, and records every
     * hyperlink target so references resolve in the render pass.
     *
     * @param list<Node> $nodes
     */
    private function collect(array $nodes, RenderState $state, bool $inTableCell): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Section) {
                $slug = $this->slug($node->title->text->text);

                if ('' !== $slug) {
                    $state->setSectionId($node, $state->claimId($slug));
                }

                $this->collectInline($node->title->text, $state, false);
                $this->collect($node->body(), $state, $inTableCell);
            } elseif ($node instanceof HyperlinkTarget) {
                $this->collectTarget($node, $state);
            } elseif ($node instanceof Paragraph) {
                $this->collectInline($node->text, $state, $inTableCell);
            } elseif ($node instanceof DefinitionListItem) {
                $this->collectInline($node->term, $state, $inTableCell);

                foreach ($node->classifiers as $classifier) {
                    $this->collectInline($classifier, $state, $inTableCell);
                }

                $this->collect($node->definition(), $state, $inTableCell);
            } elseif ($node instanceof Table) {
                foreach ($node->children() as $row) {
                    foreach ($row->children() as $cell) {
                        $this->collect($cell->children(), $state, true);
                    }
                }
            } elseif ($node instanceof ContainerNode) {
                $this->collect($node->children(), $state, $inTableCell);
            }
        }
    }

    private function collectTarget(HyperlinkTarget $target, RenderState $state): void
    {
        if ($target->anonymous) {
            $state->pushAnonymousTarget('' === $target->target ? null : $target->target);

            return;
        }

        if ('' === $target->target) {
            $semanticTarget = $state->references->target($target->name);

            if (null !== $semanticTarget && $state->references->anchorNode($semanticTarget) !== $target) {
                return;
            }

            $slug = $this->slug($target->name);

            if ('' === $slug) {
                return;
            }

            $id = $state->claimId($slug);
            $state->setTargetId($target, $id);
            $state->registerTarget($target->name, 'id', $id);

            return;
        }

        if ($this->isAliasTarget($target->target)) {
            $state->registerTarget($target->name, 'alias', $this->aliasName($target->target));

            return;
        }

        $state->registerTarget($target->name, 'url', $target->target);
    }

    private function collectInline(Text $text, RenderState $state, bool $inTableCell): void
    {
        $this->collectInlineTargets($state->inlineNodes($text, $inTableCell), $state);
    }

    /**
     * @param list<Node> $nodes
     */
    private function collectInlineTargets(array $nodes, RenderState $state): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof InlineTarget) {
                $slug = $this->slug($node->name);

                if ('' === $slug) {
                    continue;
                }

                $id = $state->claimId($slug);
                $state->setInlineTargetId($node, $id);
                $state->registerTarget($node->name, 'id', $id);
            } elseif ($node instanceof Emphasis || $node instanceof Strong) {
                $this->collectInlineTargets($node->children(), $state);
            }
        }
    }

    /**
     * @param list<Node> $nodes
     */
    private function renderChildren(array $nodes, RenderState $state, bool $inTableCell): string
    {
        $html = '';

        foreach ($nodes as $node) {
            $html .= $this->renderNode($node, $state, $inTableCell);
        }

        return $html;
    }

    private function renderNode(Node $node, RenderState $state, bool $inTableCell): string
    {
        $html = match (true) {
            $node instanceof Section => $this->renderSection($node, $state, $inTableCell),
            $node instanceof Paragraph => $this->renderParagraph($node, $state, $inTableCell),
            $node instanceof LiteralBlock => '<pre class="literal">'.$this->escape($state->source->slice($node->content))."</pre>\n",
            $node instanceof BlockQuote => "<blockquote>\n".$this->renderChildren($node->children(), $state, $inTableCell)."</blockquote>\n",
            $node instanceof BulletList => "<ul>\n".$this->renderChildren($node->children(), $state, $inTableCell)."</ul>\n",
            $node instanceof EnumeratedList => $this->renderEnumeratedList($node, $state, $inTableCell),
            $node instanceof ListItem => "<li>\n".$this->renderChildren($node->children(), $state, $inTableCell)."</li>\n",
            $node instanceof DefinitionList => "<dl>\n".$this->renderChildren($node->children(), $state, $inTableCell)."</dl>\n",
            $node instanceof DefinitionListItem => $this->renderDefinitionListItem($node, $state, $inTableCell),
            $node instanceof Table => $this->renderTable($node, $state),
            $node instanceof Directive => $this->renderDirective($node, $state),
            $node instanceof FootnoteDefinition => $this->renderFootnoteDefinition($node, $state),
            $node instanceof CitationDefinition => $this->renderCitationDefinition($node, $state),
            $node instanceof SubstitutionDefinition => '',
            $node instanceof Transition => "<hr>\n",
            $node instanceof Comment => '',
            $node instanceof HyperlinkTarget => $this->renderHyperlinkTarget($node, $state),
            $node instanceof Text => $this->escape($node->text)."\n",
            default => $this->renderFallback($node),
        };

        $id = $state->nodeId($node);
        $anchors = '';

        foreach ($state->extraAnchorIds($node) as $extraId) {
            $anchors .= '<span id="'.$this->escape($extraId)."\"></span>\n";
        }

        if (null !== $id && !$node instanceof Paragraph && !$node instanceof Section
            && !$node instanceof FootnoteDefinition && !$node instanceof CitationDefinition
            && !$node instanceof HyperlinkTarget
        ) {
            $anchors = '<span id="'.$this->escape($id)."\"></span>\n".$anchors;
        }

        return $anchors.$html;
    }

    private function renderSection(Section $section, RenderState $state, bool $inTableCell): string
    {
        $level = min($section->level, 6);
        $id = $state->sectionId($section);

        $open = null === $id ? '<section>' : '<section id="'.$this->escape($id).'">';
        $title = $this->renderInlineNodes($state->inlineNodes($section->title->text, false), $state);
        $heading = sprintf('<h%d>%s</h%d>', $level, $title, $level);

        return $open."\n".$heading."\n".$this->renderChildren($section->body(), $state, $inTableCell)."</section>\n";
    }

    private function renderParagraph(Paragraph $paragraph, RenderState $state, bool $inTableCell): string
    {
        $id = $state->nodeId($paragraph);
        $attribute = null === $id ? '' : ' id="'.$this->escape($id).'"';

        return '<p'.$attribute.'>'.$this->renderInlineNodes($state->inlineNodes($paragraph->text, $inTableCell), $state)."</p>\n";
    }

    private function renderEnumeratedList(EnumeratedList $list, RenderState $state, bool $inTableCell): string
    {
        $type = match ($list->style) {
            EnumerationStyle::LowerAlpha => 'a',
            EnumerationStyle::UpperAlpha => 'A',
            EnumerationStyle::LowerRoman => 'i',
            EnumerationStyle::UpperRoman => 'I',
            EnumerationStyle::Arabic => null,
        };
        $attributes = null === $type ? '' : sprintf(' type="%s"', $type);

        if ($list->start > 1) {
            $attributes .= sprintf(' start="%d"', $list->start);
        }

        $open = '<ol'.$attributes.'>';

        return $open."\n".$this->renderChildren($list->children(), $state, $inTableCell)."</ol>\n";
    }

    private function renderDefinitionListItem(
        DefinitionListItem $item,
        RenderState $state,
        bool $inTableCell,
    ): string {
        $term = $this->renderInlineNodes($state->inlineNodes($item->term, $inTableCell), $state);

        foreach ($item->classifiers as $classifier) {
            $term .= ' <span class="classifier-delimiter">:</span> <span class="classifier">'
                .$this->renderInlineNodes($state->inlineNodes($classifier, $inTableCell), $state)
                .'</span>';
        }

        return '<dt>'.$term."</dt>\n<dd>\n"
            .$this->renderChildren($item->definition(), $state, $inTableCell)
            ."</dd>\n";
    }

    /**
     * An internal named target becomes the anchor its references point
     * at; external and anonymous targets render nothing.
     */
    private function renderHyperlinkTarget(HyperlinkTarget $target, RenderState $state): string
    {
        $id = $state->targetId($target);

        return null === $id ? '' : '<span id="'.$this->escape($id)."\"></span>\n";
    }

    private function renderTable(Table $table, RenderState $state): string
    {
        $html = "<table>\n";

        if ([] !== $table->head) {
            $html .= "<thead>\n";

            foreach ($table->head as $row) {
                $html .= $this->renderTableRow($row, $state, 'th');
            }

            $html .= "</thead>\n";
        }

        if ([] !== $table->body) {
            $html .= "<tbody>\n";

            foreach ($table->body as $row) {
                $html .= $this->renderTableRow($row, $state, 'td');
            }

            $html .= "</tbody>\n";
        }

        return $html."</table>\n";
    }

    private function renderTableRow(TableRow $row, RenderState $state, string $tag): string
    {
        $html = "<tr>\n";

        foreach ($row->children() as $cell) {
            $attributes = $cell->colspan > 1 ? sprintf(' colspan="%d"', $cell->colspan) : '';
            $attributes .= $cell->rowspan > 1 ? sprintf(' rowspan="%d"', $cell->rowspan) : '';

            $html .= '<'.$tag.$attributes.">\n".$this->renderChildren($cell->children(), $state, true).'</'.$tag.">\n";
        }

        return $html."</tr>\n";
    }

    /**
     * @param list<Node> $nodes
     */
    private function renderInlineNodes(array $nodes, RenderState $state): string
    {
        $html = '';

        foreach ($nodes as $node) {
            $html .= $this->renderInlineNode($node, $state);
        }

        return $html;
    }

    private function renderInlineNode(Node $node, RenderState $state): string
    {
        return match (true) {
            $node instanceof InlineText => $this->escape($node->text),
            $node instanceof Emphasis => '<em>'.$this->renderInlineNodes($node->children(), $state).'</em>',
            $node instanceof Strong => '<strong>'.$this->renderInlineNodes($node->children(), $state).'</strong>',
            $node instanceof InlineLiteral => '<code>'.$this->escape($node->text).'</code>',
            $node instanceof HyperlinkReference => $this->renderHyperlinkReference($node, $state),
            $node instanceof StandaloneHyperlink => $this->renderExternalLink($node->uri, $node->uri, $state),
            $node instanceof InterpretedText => $this->renderInterpretedText($node, $state),
            $node instanceof InlineTarget => $this->renderInlineTarget($node, $state),
            $node instanceof FootnoteReference => $this->renderNoteReference($node, $state, ReferenceType::Footnote),
            $node instanceof CitationReference => $this->renderNoteReference($node, $state, ReferenceType::Citation),
            $node instanceof SubstitutionReference => $this->renderSubstitutionReference($node, $state),
            default => $this->renderFallback($node),
        };
    }

    private function renderHyperlinkReference(HyperlinkReference $reference, RenderState $state): string
    {
        $occurrence = $state->references->referenceAt($reference->span(), ReferenceType::Hyperlink);

        if (null !== $occurrence) {
            return $this->renderOccurrence($occurrence, $reference->text, $state);
        }

        if (null !== $reference->embeddedUri) {
            if ($this->isAliasTarget($reference->embeddedUri)) {
                return $this->renderResolved($state->resolve($this->aliasName($reference->embeddedUri)), $reference->text, $state);
            }

            return $this->renderExternalLink($reference->embeddedUri, $reference->text, $state);
        }

        if ($reference->anonymous) {
            $target = $state->nextAnonymousTarget();

            if (null === $target) {
                return $this->escape($reference->text);
            }

            if ($this->isAliasTarget($target)) {
                return $this->renderResolved($state->resolve($this->aliasName($target)), $reference->text, $state);
            }

            return $this->renderExternalLink($target, $reference->text, $state);
        }

        return $this->renderResolved($state->resolve($reference->text), $reference->text, $state);
    }

    private function renderOccurrence(
        ReferenceOccurrence $reference,
        string $fallbackLabel,
        RenderState $state,
    ): string {
        $label = $reference->explicitTitle ?? $fallbackLabel;

        if (ReferenceStatus::Resolved !== $reference->status || null === $reference->target) {
            return $this->escape($label);
        }

        if (null !== $reference->target->destination) {
            return $this->renderExternalLink($reference->target->destination, $label, $state);
        }

        $id = $state->definitionId($reference->target);

        return null === $id
            ? $this->escape($label)
            : '<a href="#'.$this->escape($id).'">'.$this->escape($label).'</a>';
    }

    /**
     * @param array{string, string}|null $resolution a ['url'|'id', value] pair
     */
    private function renderResolved(?array $resolution, string $label, RenderState $state): string
    {
        if (null === $resolution) {
            return $this->escape($label);
        }

        [$kind, $value] = $resolution;

        if ('id' === $kind) {
            return '<a href="#'.$this->escape($value).'">'.$this->escape($label).'</a>';
        }

        return $this->renderExternalLink($value, $label, $state);
    }

    private function renderExternalLink(string $url, string $label, RenderState $state): string
    {
        $href = $state->policy->isUrlAllowed($url) ? $this->escape($url) : '';

        return '<a href="'.$href.'">'.$this->escape($label).'</a>';
    }

    /**
     * Conservative role rendering for v1: semantic roles map to their
     * element, every other profile-known role renders its text, with an
     * explicit `Title <target>` reduced to the title. Unknown roles and
     * roles without a profile render their raw text escaped. Rendering
     * the explicit title as plain text (no link) is a documented v1
     * limitation.
     */
    private function renderInterpretedText(InterpretedText $text, RenderState $state): string
    {
        $reference = $state->references->referenceAt($text->span());

        if (null !== $reference && \in_array($reference->type, [ReferenceType::SphinxRef, ReferenceType::SphinxDoc], true)) {
            $fallback = $reference->target instanceof ReferenceDefinition
                ? $state->references->targetTitle($reference->target)
                : $this->roleTitle($text->text);

            return $this->renderOccurrence($reference, $fallback, $state);
        }

        if (null === $text->role) {
            return '<cite>'.$this->escape($text->text).'</cite>';
        }

        $spec = $state->profile?->roles->get($text->role);

        if (null === $spec) {
            return $this->escape($text->text);
        }

        $handler = $state->profile?->extensions->roleHandler($spec->name);

        if (null !== $handler && null !== $state->profile) {
            return $handler->renderHtml($text, $state->source, $state->profile, $state->policy);
        }

        $element = self::ROLE_ELEMENTS[$spec->name] ?? null;

        if (null !== $element) {
            return '<'.$element.'>'.$this->escape($text->text).'</'.$element.'>';
        }

        return $this->escape($this->roleTitle($text->text));
    }

    private function renderNoteReference(
        FootnoteReference|CitationReference $node,
        RenderState $state,
        ReferenceType $type,
    ): string {
        $reference = $state->references->referenceAt($node->span(), $type);
        $label = '['.(null === $reference ? $node->label : ($reference->displayLabel ?? $node->label)).']';

        if (null === $reference || $type !== $reference->type || ReferenceStatus::Resolved !== $reference->status || null === $reference->target) {
            return $this->escape($label);
        }

        $targetId = $state->definitionId($reference->target);
        $referenceId = $state->referenceId($reference);

        if (null === $targetId || null === $referenceId) {
            return $this->escape($label);
        }

        return '<a id="'.$this->escape($referenceId).'" class="'.$type->value.'-reference" href="#'
            .$this->escape($targetId).'">'.$this->escape($label).'</a>';
    }

    private function renderSubstitutionReference(SubstitutionReference $node, RenderState $state): string
    {
        $reference = $state->references->referenceAt($node->span(), ReferenceType::Substitution);

        if (null === $reference || ReferenceStatus::Resolved !== $reference->status || null === $reference->target) {
            return $this->escape('|'.$node->name.'|');
        }

        $replacement = $reference->target->destination;

        if (null === $replacement) {
            return $this->escape('|'.$node->name.'|');
        }

        if (SubstitutionKind::Image === $reference->target->substitutionKind) {
            $src = $state->policy->isUrlAllowed($replacement) ? $this->escape($replacement) : '';
            $alt = $this->escape($reference->target->substitutionAlt ?? $node->name);
            $replacementHtml = '<img src="'.$src.'" alt="'.$alt.'">';

            return $this->renderLinkedSubstitution($node, $replacementHtml, $state);
        }

        $text = new Text(
            $reference->target->destinationSpan
                ?? ByteSpan::of($reference->target->span->start, \strlen($replacement)),
            $replacement,
        );

        $replacementHtml = $this->renderInlineNodes($state->inlineNodes($text, false), $state);

        return $this->renderLinkedSubstitution($node, $replacementHtml, $state);
    }

    private function renderLinkedSubstitution(
        SubstitutionReference $node,
        string $replacementHtml,
        RenderState $state,
    ): string {
        if (!$node->reference) {
            return $replacementHtml;
        }

        $hyperlink = $state->references->referenceAt($node->span(), ReferenceType::Hyperlink);

        return null === $hyperlink
            ? $replacementHtml
            : $this->renderOccurrenceHtml($hyperlink, $replacementHtml, $state);
    }

    private function renderOccurrenceHtml(
        ReferenceOccurrence $reference,
        string $labelHtml,
        RenderState $state,
    ): string {
        if (ReferenceStatus::Resolved !== $reference->status || null === $reference->target) {
            return $labelHtml;
        }

        if (null !== $reference->target->destination) {
            $href = $state->policy->isUrlAllowed($reference->target->destination)
                ? $this->escape($reference->target->destination)
                : '';

            return '<a href="'.$href.'">'.$labelHtml.'</a>';
        }

        $id = $state->definitionId($reference->target);

        return null === $id ? $labelHtml : '<a href="#'.$this->escape($id).'">'.$labelHtml.'</a>';
    }

    /**
     * Reduces a cross-reference argument written `Title <target>` to its
     * explicit title; anything else passes through unchanged.
     */
    private function roleTitle(string $text): string
    {
        if (1 === preg_match('/^(.*\S)\s*<[^<>]*>$/s', $text, $matches)) {
            return $matches[1];
        }

        return $text;
    }

    private function renderInlineTarget(InlineTarget $target, RenderState $state): string
    {
        $id = $state->inlineTargetId($target);

        if (null === $id) {
            return $this->escape($target->name);
        }

        return '<span id="'.$this->escape($id).'">'.$this->escape($target->name).'</span>';
    }

    private function renderFootnoteDefinition(FootnoteDefinition $definition, RenderState $state): string
    {
        $graphDefinition = $this->graphDefinitionForNode($definition, $state);
        $label = null === $graphDefinition
            ? $definition->label
            : ($state->references->displayLabel($graphDefinition) ?? $definition->label);

        return $this->renderNoteDefinition($definition, $label, 'footnote', $state);
    }

    private function renderCitationDefinition(CitationDefinition $definition, RenderState $state): string
    {
        return $this->renderNoteDefinition($definition, $definition->label, 'citation', $state);
    }

    private function renderNoteDefinition(
        FootnoteDefinition|CitationDefinition $definition,
        string $label,
        string $class,
        RenderState $state,
    ): string {
        $graphDefinition = $this->graphDefinitionForNode($definition, $state);

        if (null === $graphDefinition) {
            return $this->renderChildren($definition->children(), $state, false);
        }

        $id = $state->definitionId($graphDefinition);

        if (null === $id) {
            return $this->renderChildren($definition->children(), $state, false);
        }

        $backlinks = '';

        foreach ($state->backReferenceIds($graphDefinition) as $index => $referenceId) {
            $suffix = 0 === $index ? '' : (string) ($index + 1);
            $backlinks .= '<a class="backref" href="#'.$this->escape($referenceId).'">'.$this->escape('back'.$suffix).'</a>';
        }

        $html = '<aside id="'.$this->escape($id).'" class="'.$class."\">\n";
        $html .= '<span class="label">'.$this->escape('['.$label.']').'</span>';

        if ('' !== $backlinks) {
            $html .= '<span class="backrefs">'.$backlinks.'</span>';
        }

        return $html."\n".$this->renderChildren($definition->children(), $state, false)."</aside>\n";
    }

    private function graphDefinitionForNode(Node $node, RenderState $state): ?ReferenceDefinition
    {
        foreach ($state->references->definitions() as $definition) {
            if ($definition->node === $node) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * A target string ending in a single underscore references another
     * target instead of naming a URL.
     */
    private function isAliasTarget(string $target): bool
    {
        return \strlen($target) > 1 && str_ends_with($target, '_') && !str_ends_with($target, '\_');
    }

    private function aliasName(string $target): string
    {
        $name = substr($target, 0, -1);

        if (\strlen($name) > 1 && str_starts_with($name, '`') && str_ends_with($name, '`')) {
            $name = substr($name, 1, -1);
        }

        return $name;
    }

    private function renderDirective(Directive $directive, RenderState $state): string
    {
        if (null === $state->profile) {
            return $this->renderDirectiveWithoutProfile($directive, $state);
        }

        $name = strtolower($directive->name);

        if ('sourcecode' === $name) {
            $name = 'code-block';
        }

        if (!$state->profile->directives->isEnabled($name)) {
            return $this->directivePlaceholder($directive);
        }

        $handler = $state->profile->extensions->directiveHandler($name);

        if (null !== $handler) {
            return $handler->renderHtml(
                $directive,
                $state->source,
                $state->profile,
                $state->policy,
                new DirectiveRenderContext(
                    fn (Directive $body): string => DirectiveBodyKind::Blocks === $body->bodyKind
                        ? $this->renderChildren($body->children(), $state, false)
                        : '',
                ),
            );
        }

        if ('code-block' === $name) {
            return $this->renderCodeBlock($directive, $state);
        }

        if (isset(self::ADMONITION_TITLES[$name])) {
            return $this->renderAdmonition($name, self::ADMONITION_TITLES[$name], $directive, $state);
        }

        if ('admonition' === $name) {
            return $this->renderAdmonition($name, trim($directive->arguments[0] ?? ''), $directive, $state);
        }

        if (isset(self::VERSION_PREFIXES[$name])) {
            return $this->renderVersionNote($name, $directive, $state);
        }

        if ('image' === $name) {
            return $this->renderImage($directive, $state)."\n";
        }

        if ('figure' === $name) {
            return $this->renderFigure($directive, $state);
        }

        return $this->directivePlaceholder($directive);
    }

    /**
     * The v0 behavior, kept as the default: the base admonition set
     * renders, everything else is an inert comment placeholder.
     */
    private function renderDirectiveWithoutProfile(Directive $directive, RenderState $state): string
    {
        $name = strtolower($directive->name);

        if (!isset(self::ADMONITIONS[$name])) {
            return $this->directivePlaceholder($directive);
        }

        if (null === $directive->rawBody) {
            return '<div class="admonition '.$name."\"></div>\n";
        }

        $body = $this->escape($state->source->slice($directive->rawBody));

        return '<div class="admonition '.$name."\">\n<p>".$body."</p>\n</div>\n";
    }

    private function renderAdmonition(string $name, string $title, Directive $directive, RenderState $state): string
    {
        $html = '<div class="admonition '.$this->escape($name)."\">\n";

        if ('' !== $title) {
            $html .= '<p class="admonition-title">'.$this->escape($title)."</p>\n";
        }

        if (DirectiveBodyKind::Blocks === $directive->bodyKind) {
            $html .= $this->renderChildren($directive->children(), $state, false);
        } elseif (null !== $directive->rawBody) {
            $html .= '<p>'.$this->escape($this->dedent($state->source->slice($directive->rawBody)))."</p>\n";
        }

        return $html."</div>\n";
    }

    private function renderCodeBlock(Directive $directive, RenderState $state): string
    {
        $attribute = '';
        $language = trim($directive->arguments[0] ?? '');

        if ('' !== $language) {
            $attribute = ' class="language-'.$this->escape($language).'"';
        }

        $body = null === $directive->rawBody ? '' : $this->dedent($state->source->slice($directive->rawBody));

        return '<pre><code'.$attribute.'>'.$this->escape($body)."</code></pre>\n";
    }

    private function renderVersionNote(string $name, Directive $directive, RenderState $state): string
    {
        $html = '<div class="version-note '.$this->escape($name)."\">\n";
        $version = trim($directive->arguments[0] ?? '');

        if ('' !== $version) {
            $html .= '<p>'.$this->escape(self::VERSION_PREFIXES[$name].' '.$version)."</p>\n";
        }

        if (DirectiveBodyKind::Blocks === $directive->bodyKind) {
            $html .= $this->renderChildren($directive->children(), $state, false);
        } elseif (null !== $directive->rawBody) {
            $html .= '<p>'.$this->escape($this->dedent($state->source->slice($directive->rawBody)))."</p>\n";
        }

        return $html."</div>\n";
    }

    private function renderImage(Directive $directive, RenderState $state): string
    {
        $uri = trim($directive->arguments[0] ?? '');
        $src = '' !== $uri && $state->policy->isUrlAllowed($uri) ? $this->escape($uri) : '';
        $attributes = ' src="'.$src.'"';

        $alt = $directive->options['alt'] ?? null;

        if (null !== $alt) {
            $attributes .= ' alt="'.$this->escape($alt).'"';
        }

        return '<img'.$attributes.'>';
    }

    private function renderFigure(Directive $directive, RenderState $state): string
    {
        $html = "<figure>\n".$this->renderImage($directive, $state)."\n";

        if (DirectiveBodyKind::Blocks === $directive->bodyKind) {
            $children = $directive->children();
            $caption = array_shift($children);

            if ($caption instanceof Paragraph) {
                $html .= '<figcaption>'
                    .$this->renderInlineNodes($state->inlineNodes($caption->text, false), $state)
                    ."</figcaption>\n";
            } elseif (null !== $caption) {
                array_unshift($children, $caption);
            }

            $html .= $this->renderChildren($children, $state, false);
        } elseif (null !== $directive->rawBody) {
            $body = $this->dedent($state->source->slice($directive->rawBody));
            $parts = preg_split('/\n[ \t]*\n/', $body, 2);
            $caption = trim(false === $parts ? $body : $parts[0]);

            if ('' !== $caption) {
                $html .= '<figcaption>'.$this->escape($caption)."</figcaption>\n";
            }
        }

        return $html."</figure>\n";
    }

    private function directivePlaceholder(Directive $directive): string
    {
        return '<!-- directive: '.$this->commentSafe($directive->name)." -->\n";
    }

    private function renderFallback(Node $node): string
    {
        $class = $node::class;
        $short = false === ($pos = strrpos($class, '\\')) ? $class : substr($class, $pos + 1);

        return '<!-- node: '.$this->commentSafe($short)." -->\n";
    }

    /**
     * Strips the common leading space indentation of every non-blank
     * line, the way a directive body sheds the indentation that nested
     * it under its marker.
     */
    private function dedent(string $text): string
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

        foreach ($lines as $index => $line) {
            $lines[$index] = substr($line, min($indent, \strlen($line)));
        }

        return implode("\n", $lines);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Collapsing hyphen runs makes "--" impossible, so the placeholder
     * can never terminate its own HTML comment.
     */
    private function commentSafe(string $text): string
    {
        return $this->escape((string) preg_replace('/-{2,}/', '-', $text));
    }

    private function slug(string $title): string
    {
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title));

        return trim($slug, '-');
    }
}
