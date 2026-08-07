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

namespace Alto\Rst\Convert\Writer;

use Alto\Rst\Node\Document;
use Alto\Rst\Node\HyperlinkTarget;
use Alto\Rst\Node\Section;
use Alto\Rst\Reference\DefinitionKind;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Reference\ReferenceName;

/**
 * The resolved hyperlink targets of one document.
 *
 * Built in a pre-pass so the writer can turn `name_` references into
 * Markdown reference links, pair anonymous references with anonymous
 * targets in document order, and map internal targets that announce a
 * section onto that section's implicit heading anchor. Indirect targets
 * ("``.. _a: b_``") and chained targets (an empty target adopting the next
 * target's destination) resolve here, matching docutils.
 *
 * A parent map makes an admonition body see the targets of its enclosing
 * document.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class TargetMap
{
    private const int MAX_INDIRECTION = 10;

    /**
     * @var array<string, string> normalized name to URL
     */
    private array $urls = [];

    /**
     * @var array<string, string> normalized name to section title
     */
    private array $sectionTitles = [];

    /**
     * @var array<string, string> normalized name to the anchor id written
     *                            for a standalone internal target
     */
    private array $anchors = [];

    /**
     * @var list<array{label: string, url: string}>
     */
    private array $definitions = [];

    /**
     * @var list<string>
     */
    private array $anonymousUrls = [];

    private int $anonymousCursor = 0;

    private function __construct(
        private readonly ?self $parent,
    ) {
    }

    public static function fromDocument(
        Document $document,
        ?self $parent = null,
        ?ReferenceGraph $references = null,
    ): self {
        $map = new self($parent);
        $map->addDocument($document, $references);

        return $map;
    }

    /**
     * Adds targets from content logically inserted into this document.
     */
    public function addDocument(Document $document, ?ReferenceGraph $references = null): void
    {
        $nodes = iterator_to_array($document->descendants(), false);
        $sectionTitles = [];

        if (null !== $references) {
            foreach ($references->definitions(DefinitionKind::Section) as $definition) {
                if ($definition->node instanceof Section) {
                    $sectionTitles[spl_object_id($definition->node)] = $references->targetTitle($definition);
                }
            }
        }

        foreach ($nodes as $node) {
            if ($node instanceof Section) {
                $title = $sectionTitles[spl_object_id($node)] ?? $node->title->text->text;
                $this->sectionTitles[self::normalize($title)] ??= $title;
            }
        }

        /** @var list<array{label: string, name: string}> $order */
        $order = [];

        /** @var array<string, string> $raw normalized name to raw target text */
        $raw = [];

        /** @var list<string> $rawAnonymous */
        $rawAnonymous = [];

        /** @var list<string> $chained normalized names waiting for the next target */
        $chained = [];

        $count = \count($nodes);

        for ($index = 0; $index < $count; ++$index) {
            $node = $nodes[$index];

            if (!$node instanceof HyperlinkTarget) {
                continue;
            }

            if ($node->anonymous) {
                if ('' !== $node->target) {
                    $rawAnonymous[] = $node->target;

                    foreach ($chained as $name) {
                        $raw[$name] ??= $node->target;
                    }
                }

                $chained = [];

                continue;
            }

            $name = self::normalize($node->name);

            if ('' !== $node->target) {
                if (!\array_key_exists($name, $raw)) {
                    $raw[$name] = $node->target;
                    $order[] = ['label' => $node->name, 'name' => $name];
                }

                foreach ($chained as $chainedName) {
                    if (!\array_key_exists($chainedName, $raw)) {
                        $raw[$chainedName] = $node->target;
                    }
                }

                $chained = [];

                continue;
            }

            $next = $nodes[$index + 1] ?? null;

            if ($next instanceof HyperlinkTarget) {
                $chained[] = $name;

                continue;
            }

            if ($next instanceof Section) {
                $this->sectionTitles[$name] ??= $sectionTitles[spl_object_id($next)] ?? $next->title->text->text;

                continue;
            }

            $this->anchors[$name] ??= self::anchorId($node->name);
        }

        foreach ($chained as $name) {
            $this->anchors[$name] ??= self::anchorId($name);
        }

        foreach ($order as $entry) {
            if (
                isset($this->urls[$entry['name']])
                || isset($this->anchors[$entry['name']])
                || isset($this->sectionTitles[$entry['name']])
            ) {
                continue;
            }

            $url = $this->resolveRaw($raw[$entry['name']], $raw, 0);

            if (null === $url) {
                $this->anchors[$entry['name']] ??= self::anchorId($entry['label']);

                continue;
            }

            $this->urls[$entry['name']] ??= $url;
            $this->definitions[] = ['label' => $entry['label'], 'url' => $url];
        }

        // Chained names resolved through a later target are not in $order;
        // give them a URL (no definition of their own is needed for output
        // determinism, but references to them must resolve).
        foreach ($raw as $name => $rawTarget) {
            if (
                isset($this->urls[$name])
                || isset($this->anchors[$name])
                || isset($this->sectionTitles[$name])
            ) {
                continue;
            }

            $url = $this->resolveRaw($rawTarget, $raw, 0);

            if (null === $url) {
                $this->anchors[$name] ??= self::anchorId($name);

                continue;
            }

            $this->urls[$name] = $url;
            $this->definitions[] = ['label' => $name, 'url' => $url];
        }

        foreach ($rawAnonymous as $rawTarget) {
            $url = $this->resolveRaw($rawTarget, $raw, 0);

            if (null !== $url) {
                $this->anonymousUrls[] = $url;
            }
        }
    }

    public function urlFor(string $name): ?string
    {
        $normalized = self::normalize($name);

        return $this->urls[$normalized] ?? $this->parent?->urlFor($name);
    }

    public function sectionTitleFor(string $name): ?string
    {
        $normalized = self::normalize($name);

        return $this->sectionTitles[$normalized] ?? $this->parent?->sectionTitleFor($name);
    }

    /**
     * The anchor id written for a standalone internal target, or null when
     * the name resolves to a URL or a section instead.
     */
    public function anchorFor(string $name): ?string
    {
        $normalized = self::normalize($name);

        if (isset($this->urls[$normalized]) || isset($this->sectionTitles[$normalized])) {
            return null;
        }

        return $this->anchors[$normalized] ?? $this->parent?->anchorFor($name);
    }

    public function takeAnonymousUrl(): ?string
    {
        if ($this->anonymousCursor >= \count($this->anonymousUrls)) {
            return null;
        }

        return $this->anonymousUrls[$this->anonymousCursor++];
    }

    /**
     * Named targets with a resolved URL, in document order.
     *
     * @return list<array{label: string, url: string}>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * The docutils simple-name normalization: case-folded on ASCII, with
     * whitespace runs collapsed to a single space.
     */
    public static function normalize(string $name): string
    {
        return ReferenceName::normalize($name);
    }

    /**
     * @param array<string, string> $raw
     */
    private function resolveRaw(string $rawTarget, array $raw, int $depth): ?string
    {
        if ('' === $rawTarget || $depth > self::MAX_INDIRECTION) {
            return null;
        }

        $reference = self::indirectReferenceName($rawTarget);

        if (null === $reference) {
            return $rawTarget;
        }

        if (\array_key_exists($reference, $raw)) {
            return $this->resolveRaw($raw[$reference], $raw, $depth + 1);
        }

        if (isset($this->sectionTitles[$reference])) {
            return '#'.self::slug($this->sectionTitles[$reference]);
        }

        return $this->parent?->urlFor($reference);
    }

    /**
     * Detects the "name_" indirect target form. URLs that merely end with
     * an underscore do not qualify: an indirect reference has no scheme
     * and no path separator.
     */
    private static function indirectReferenceName(string $rawTarget): ?string
    {
        if (1 === preg_match('/^`(.+)`_$/', $rawTarget, $matches)) {
            return self::normalize($matches[1]);
        }

        if (str_contains($rawTarget, '/') || str_contains($rawTarget, ':')) {
            return null;
        }

        if (1 === preg_match('/^([A-Za-z0-9][A-Za-z0-9._+-]*)_$/', $rawTarget, $matches)) {
            return self::normalize($matches[1]);
        }

        return null;
    }

    /**
     * A safe HTML id for a standalone internal target.
     */
    private static function anchorId(string $name): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9._:-]+/', '-', $name), '-');
    }

    /**
     * The GitHub heading anchor for a section title: lowercased, with
     * punctuation dropped and spaces turned into hyphens.
     */
    public static function slug(string $title): string
    {
        $slug = strtolower($title);
        $slug = (string) preg_replace('/[^a-z0-9 _-]/', '', $slug);

        return str_replace(' ', '-', $slug);
    }
}
