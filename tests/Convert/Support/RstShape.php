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

namespace Alto\Rst\Tests\Convert\Support;

use Alto\Rst\Convert\Writer\TargetMap;
use Alto\Rst\Node\BlockQuote;
use Alto\Rst\Node\BulletList;
use Alto\Rst\Node\Comment;
use Alto\Rst\Node\DefinitionList;
use Alto\Rst\Node\DefinitionListItem;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\DirectiveBodyKind;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\EnumeratedList;
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
use Alto\Rst\Node\Table;
use Alto\Rst\Node\TableRow;
use Alto\Rst\Node\Text;
use Alto\Rst\Node\Transition;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Parser\InlineParser;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Source\Source;

/**
 * Reduces an RST tree to a nested-array semantic shape for round-trip
 * comparison: node kinds, resolved link destinations, normalized text,
 * with byte positions and target placement abstracted away.
 */
final class RstShape
{
    private readonly TargetMap $targets;

    private readonly InlineParser $inlineParser;

    private function __construct(
        private readonly Source $source,
        Document $document,
    ) {
        $this->targets = TargetMap::fromDocument($document);
        $this->inlineParser = new InlineParser(new ProblemCollector());
    }

    /**
     * @return array<string, mixed>
     */
    public static function of(string $rst): array
    {
        $source = Source::fromString($rst);
        $document = new BlockParser()->parse($source)->document();
        $shape = new self($source, $document);

        $targets = [];

        foreach ($shape->targets->definitions() as $definition) {
            $targets[TargetMap::normalize($definition['label'])] = $definition['url'];
        }

        ksort($targets);

        return [
            'body' => $shape->nodes($document->children()),
            'targets' => $targets,
        ];
    }

    /**
     * @param list<Node> $nodes
     *
     * @return list<mixed>
     */
    private function nodes(array $nodes): array
    {
        $shapes = [];

        foreach ($nodes as $node) {
            $shape = $this->node($node);

            if (null !== $shape) {
                $shapes[] = $shape;
            }
        }

        return $shapes;
    }

    private function node(Node $node): mixed
    {
        return match (true) {
            $node instanceof Section => ['section', $node->level, self::normalize($node->title->text->text), $this->nodes($node->body())],
            $node instanceof Paragraph => ['p', $this->inline($node->text)],
            $node instanceof LiteralBlock => ['literal', self::normalizeBlock($this->source->slice($node->content))],
            $node instanceof BlockQuote => ['quote', $this->nodes($node->children())],
            $node instanceof BulletList => ['ul', $node->marker, array_map(fn (ListItem $item): array => $this->nodes($item->children()), $node->children())],
            $node instanceof EnumeratedList => ['ol', $node->style->value, $node->start, array_map(fn (ListItem $item): array => $this->nodes($item->children()), $node->children())],
            $node instanceof DefinitionList => ['dl', array_map($this->definitionItem(...), $node->children())],
            $node instanceof Table => ['table', array_map($this->row(...), $node->head), array_map($this->row(...), $node->body)],
            $node instanceof Directive => $this->directive($node),
            $node instanceof Transition => ['hr'],
            $node instanceof Comment => ['comment', self::normalize($node->text)],
            $node instanceof HyperlinkTarget => null,
            default => [$node::class],
        };
    }

    /**
     * @return list<mixed>
     */
    private function definitionItem(DefinitionListItem $item): array
    {
        return [
            $this->inline($item->term),
            array_map($this->inline(...), $item->classifiers),
            $this->nodes($item->definition()),
        ];
    }

    /**
     * @return list<mixed>
     */
    private function row(TableRow $row): array
    {
        $cells = [];

        foreach ($row->children() as $cell) {
            $cells[] = $this->nodes($cell->children());
        }

        return $cells;
    }

    /**
     * @return list<mixed>
     */
    private function directive(Directive $directive): array
    {
        $name = strtolower($directive->name);
        $options = $directive->options;
        ksort($options);

        $body = null === $directive->rawBody ? '' : $this->source->slice($directive->rawBody);

        if (\in_array($name, ['note', 'tip', 'important', 'warning', 'caution', 'hint', 'seealso', 'attention', 'danger', 'error', 'versionadded', 'versionchanged', 'deprecated', 'admonition', 'figure'], true)) {
            if (DirectiveBodyKind::Blocks === $directive->bodyKind) {
                return ['directive', $name, array_map(trim(...), $directive->arguments), $options, $this->nodes($directive->children())];
            }

            $sub = self::of(self::dedent($body));

            return ['directive', $name, array_map(trim(...), $directive->arguments), $options, $sub['body']];
        }

        return ['directive', $name, array_map(trim(...), $directive->arguments), $options, self::normalizeBlock($body)];
    }

    /**
     * @return list<mixed>
     */
    private function inline(Text $text): array
    {
        $nodes = $this->inlineParser->parse($text->text, $text->span()->start);

        return $this->inlineShapes($nodes);
    }

    /**
     * @param list<Node> $nodes
     *
     * @return list<mixed>
     */
    private function inlineShapes(array $nodes): array
    {
        $shapes = [];
        $buffer = '';

        $flush = static function () use (&$shapes, &$buffer): void {
            $normalized = self::normalize($buffer);

            if ('' !== $normalized) {
                $shapes[] = ['t', $normalized];
            }

            $buffer = '';
        };

        foreach ($nodes as $node) {
            if ($node instanceof InlineText) {
                $buffer .= $node->text;

                continue;
            }

            $flush();

            $shapes[] = match (true) {
                $node instanceof Emphasis => ['em', $this->inlineShapes($node->children())],
                $node instanceof Strong => ['strong', $this->inlineShapes($node->children())],
                $node instanceof InlineLiteral => ['lit', $node->text],
                $node instanceof HyperlinkReference => $this->link($node),
                $node instanceof StandaloneHyperlink => ['link', $node->uri, $node->uri],
                $node instanceof InterpretedText => ['role', strtolower($node->role ?? ''), self::normalize($node->text)],
                $node instanceof FootnoteReference => ['footnote', $node->label],
                $node instanceof CitationReference => ['citation', $node->label],
                $node instanceof SubstitutionReference => ['substitution', $node->name],
                $node instanceof InlineTarget => ['inline-target', self::normalize($node->name)],
                default => [$node::class],
            };
        }

        $flush();

        return $shapes;
    }

    /**
     * @return list<mixed>
     */
    private function link(HyperlinkReference $reference): array
    {
        $url = $reference->embeddedUri;

        if (null === $url && $reference->anonymous) {
            $url = $this->targets->takeAnonymousUrl();
        }

        if (null === $url) {
            $url = $this->targets->urlFor($reference->text);
        }

        if (null === $url) {
            $sectionTitle = $this->targets->sectionTitleFor($reference->text);
            $url = null === $sectionTitle ? null : '#'.TargetMap::slug($sectionTitle);
        }

        if (null === $url) {
            $anchor = $this->targets->anchorFor($reference->text);
            $url = null === $anchor ? '?' : '#'.$anchor;
        }

        return ['link', self::normalize($reference->text), $url];
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private static function normalizeBlock(string $text): string
    {
        return self::dedent($text);
    }

    private static function dedent(string $text): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $margin = null;

        foreach ($lines as $line) {
            if ('' === trim($line)) {
                continue;
            }

            $indent = \strlen($line) - \strlen(ltrim($line, " \t"));
            $margin = null === $margin ? $indent : min($margin, $indent);
        }

        $margin ??= 0;
        $dedented = [];

        foreach ($lines as $line) {
            $dedented[] = rtrim(substr($line, min($margin, \strlen($line) - \strlen(ltrim($line, " \t")))));
        }

        return trim(implode("\n", $dedented), "\n");
    }
}
