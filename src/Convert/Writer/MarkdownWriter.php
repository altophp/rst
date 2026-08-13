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

use Alto\Rst\Convert\AdmonitionStyle;
use Alto\Rst\Convert\ConversionIssue;
use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\ConversionReport;
use Alto\Rst\Convert\ConversionResult;
use Alto\Rst\Convert\HeadingStyle;
use Alto\Rst\Convert\LinkStyle;
use Alto\Rst\Exception\FileReadException;
use Alto\Rst\Extension\DirectiveConversionContext;
use Alto\Rst\Node\BlockQuote;
use Alto\Rst\Node\BulletList;
use Alto\Rst\Node\CitationDefinition;
use Alto\Rst\Node\Comment;
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
use Alto\Rst\Node\TableCell;
use Alto\Rst\Node\TableRow;
use Alto\Rst\Node\Text;
use Alto\Rst\Node\Transition;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Parser\InlineParser;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Reference\DefinitionKind;
use Alto\Rst\Reference\ProjectReferenceMap;
use Alto\Rst\Reference\ReferenceDefinition;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Reference\ReferenceName;
use Alto\Rst\Reference\ReferenceStatus;
use Alto\Rst\Reference\ReferenceType;
use Alto\Rst\Reference\SubstitutionKind;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;

/**
 * Walks the RST ROM and writes GitHub-flavored Markdown.
 *
 * Every construct without a Markdown equivalent produces a ConversionIssue
 * and a conservative degradation; nothing is dropped silently. The issue
 * report is the quality metric: the target is zero unsupported entries on
 * the production corpus.
 *
 * @internal consumed through Alto\Rst\Convert\RstToMarkdown
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class MarkdownWriter
{
    /**
     * @var array<string, true>
     */
    private const array CODE_DIRECTIVES = ['code-block' => true, 'code' => true, 'sourcecode' => true];

    /**
     * Admonition names outside the five GitHub alerts, mapped to the
     * nearest alert kind. Each use is recorded as an approximation.
     *
     * @var array<string, string>
     */
    private const array NEAREST_ALERT = [
        'hint' => 'TIP',
        'seealso' => 'NOTE',
        'admonition' => 'NOTE',
        'attention' => 'WARNING',
        'danger' => 'CAUTION',
        'error' => 'CAUTION',
    ];

    /**
     * @var array<string, string>
     */
    private const array VERSION_LABELS = [
        'versionadded' => 'New in version %s',
        'versionchanged' => 'Changed in version %s',
        'deprecated' => 'Deprecated since version %s',
    ];

    /**
     * Image directive options the Markdown image syntax cannot carry.
     *
     * @var list<string>
     */
    private const array DROPPED_IMAGE_OPTIONS = ['width', 'height', 'scale', 'align', 'class', 'name', 'loading'];

    /**
     * Roles whose value is fully represented by a Markdown code span.
     *
     * @var list<string>
     */
    private const array CODE_LIKE_ROLES = [
        'literal',
        'code',
        'envvar',
        'token',
        'keyword',
        'option',
        'command',
        'file',
        'kbd',
        'mailheader',
        'makevar',
        'manpage',
        'mimetype',
        'newsgroup',
        'program',
        'regexp',
        'samp',
        'namespace',
    ];

    private readonly TargetMap $targets;

    private readonly InlineParser $inlineParser;

    /**
     * Project document that owns the generated Markdown output.
     *
     * Included fragments keep their physical source path for file access but
     * resolve links relative to the document into which they are expanded.
     */
    private readonly ?string $documentPath;

    /**
     * @var list<ConversionIssue>
     */
    private array $issues = [];

    /**
     * @var list<array{label: string, url: string}>
     */
    private array $extraDefinitions = [];

    /**
     * @var array<string, true>
     */
    private array $indexedIncludes = [];

    /**
     * @var array<string, ByteSpan>
     */
    private array $resolvedReferenceSpans = [];

    /**
     * @var array<int, string>
     */
    private array $footnoteIds = [];

    /**
     * @param list<string> $includeStack
     */
    public function __construct(
        private readonly Document $document,
        private readonly Source $source,
        private readonly Profile $profile,
        private readonly ConversionOptions $options,
        ?TargetMap $parentTargets = null,
        private readonly ?ReferenceGraph $references = null,
        private readonly ?ProjectReferenceMap $projectReferences = null,
        private readonly ?string $sourcePath = null,
        private readonly array $includeStack = [],
        private readonly bool $includedFragment = false,
        ?string $documentPath = null,
    ) {
        $this->documentPath = $documentPath ?? $sourcePath;
        $this->targets = TargetMap::fromDocument($document, $parentTargets, $references);
        $this->inlineParser = new InlineParser(new ProblemCollector());

        if (null !== $this->references) {
            $fragmentPrefix = $this->fragmentAnchorPrefix();
            $footnotePrefix = null === $fragmentPrefix ? 'fn' : 'fn-' . $fragmentPrefix;

            foreach ($this->references->definitions(DefinitionKind::Footnote) as $index => $definition) {
                $this->footnoteIds[spl_object_id($definition)] = $footnotePrefix . '-' . ($index + 1);
            }
        }

        if (!$this->includedFragment) {
            $stack = [] === $this->includeStack && null !== $this->sourcePath
                ? [$this->sourcePath]
                : $this->includeStack;
            $this->indexIncludedTargets($document, $this->sourcePath, $stack);
        }
    }

    public function write(): ConversionResult
    {
        $blocks = $this->documentBlocks();
        $output = implode("\n\n", $blocks);

        return new ConversionResult(
            '' === $output ? '' : $output . "\n",
            new ConversionReport($this->issues),
            array_values($this->resolvedReferenceSpans),
        );
    }

    /**
     * @return list<string>
     */
    private function documentBlocks(): array
    {
        $blocks = $this->blocks($this->document->children());
        $definitions = $this->definitionLines();

        if ([] !== $definitions) {
            $blocks[] = implode("\n", $definitions);
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    private function definitionLines(): array
    {
        if ($this->includedFragment || LinkStyle::Inline === $this->options->linkStyle) {
            return [];
        }

        $lines = [];
        $seen = [];

        foreach ([...$this->targets->definitions(), ...$this->extraDefinitions] as $definition) {
            $key = TargetMap::normalize($definition['label']);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $lines[] = '[' . $this->escapeText($definition['label']) . ']: ' . $definition['url'];
        }

        return $lines;
    }

    /**
     * @param list<Node> $nodes
     *
     * @return list<string>
     */
    private function blocks(array $nodes): array
    {
        $blocks = [];

        foreach ($nodes as $node) {
            foreach ($this->blocksFor($node) as $block) {
                if ('' !== $block) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    private function blocksFor(Node $node): array
    {
        return match (true) {
            $node instanceof Section => $this->sectionBlocks($node),
            $node instanceof Paragraph => [$this->escapeLineStart($this->inlineFromText($node->text))],
            $node instanceof LiteralBlock => [$this->fencedBlock('', Lines::dedent($this->source->slice($node->content)))],
            $node instanceof BlockQuote => [Lines::quote(implode("\n\n", $this->blocks($node->children())))],
            $node instanceof BulletList => [$this->bulletListBlock($node)],
            $node instanceof EnumeratedList => [$this->enumeratedListBlock($node)],
            $node instanceof DefinitionList => [$this->definitionListBlock($node)],
            $node instanceof Table => [$this->tableBlock($node)],
            $node instanceof Directive => $this->directiveBlocks($node),
            $node instanceof FootnoteDefinition => [$this->footnoteBlock($node)],
            $node instanceof CitationDefinition => [$this->citationBlock($node)],
            $node instanceof SubstitutionDefinition => [],
            $node instanceof Transition => ['---'],
            $node instanceof Comment => [$this->commentBlock($node)],
            $node instanceof HyperlinkTarget => $this->targetBlocks($node),
            default => $this->fallbackBlocks($node),
        };
    }

    /**
     * @return list<string>
     */
    private function sectionBlocks(Section $section): array
    {
        $title = $this->inlineFromText($section->title->text);
        $level = $section->level;

        if ($level > 6) {
            $this->issue(ConversionIssue::lossy(
                'section:level',
                sprintf('Markdown headings stop at level 6; level %d flattened.', $level),
                $section->title->span(),
            ));
            $level = 6;
        }

        if (HeadingStyle::Setext === $this->options->headingStyle && $level <= 2) {
            $underline = str_repeat(1 === $level ? '=' : '-', max(3, \strlen($title)));
            $heading = $title . "\n" . $underline;
        } else {
            $heading = str_repeat('#', $level) . ' ' . $title;
        }

        return [$heading, ...$this->blocks($section->body())];
    }

    private function bulletListBlock(BulletList $list): string
    {
        $marker = $list->marker;

        if (1 !== \strlen($marker) || !str_contains('-*+', $marker)) {
            $this->issue(ConversionIssue::approximated(
                'list:bullet-marker',
                sprintf('Bullet marker "%s" has no Markdown equivalent; "%s" used.', $marker, $this->options->bulletMarker),
                $list->span(),
            ));
            $marker = $this->options->bulletMarker;
        }

        $items = [];

        foreach ($list->children() as $item) {
            $items[] = $this->listItemBlock($item, $marker . ' ');
        }

        return implode($this->listSeparator($list->children()), $items);
    }

    private function enumeratedListBlock(EnumeratedList $list): string
    {
        if (EnumerationStyle::Arabic !== $list->style) {
            $this->issue(ConversionIssue::lossy(
                'list:enumeration-style',
                sprintf('Markdown only numbers with arabic digits; "%s" numbering rewritten.', $list->style->value),
                $list->span(),
            ));
        }

        $number = max(1, $list->start);
        $items = [];

        foreach ($list->children() as $item) {
            $items[] = $this->listItemBlock($item, $number . '. ');
            ++$number;
        }

        return implode($this->listSeparator($list->children()), $items);
    }

    private function listItemBlock(ListItem $item, string $marker): string
    {
        $content = implode("\n\n", $this->blocks($item->children()));

        if ('' === $content) {
            return rtrim($marker);
        }

        return Lines::prefix($content, $marker, str_repeat(' ', \strlen($marker)));
    }

    private function definitionListBlock(DefinitionList $list): string
    {
        $this->issue(ConversionIssue::approximated(
            'definition-list',
            'Markdown has no native definition lists; terms and definitions were written as bullet items.',
            $list->span(),
        ));
        $items = [];

        foreach ($list->children() as $item) {
            $items[] = $this->definitionListItemBlock($item);
        }

        return implode("\n\n", $items);
    }

    private function definitionListItemBlock(DefinitionListItem $item): string
    {
        $label = '**' . $this->inlineFromText($item->term) . '**';

        if ([] !== $item->classifiers) {
            $classifiers = array_map($this->inlineFromText(...), $item->classifiers);
            $label .= ' (' . implode(', ', $classifiers) . ')';
        }

        $content = implode("\n\n", [$label, ...$this->blocks($item->definition())]);

        return Lines::prefix($content, '- ', '  ');
    }

    /**
     * A list is written tight when every item is a single paragraph,
     * matching the way documentation lists overwhelmingly read.
     *
     * @param list<ListItem> $items
     */
    private function listSeparator(array $items): string
    {
        foreach ($items as $item) {
            $children = $item->children();

            if (1 !== \count($children) || !$children[0] instanceof Paragraph) {
                return "\n\n";
            }
        }

        return "\n";
    }

    private function tableBlock(Table $table): string
    {
        $columns = max(1, \count($table->columnWidths));
        $head = $table->head;
        $body = $table->body;

        if ([] === $head) {
            $this->issue(ConversionIssue::approximated(
                'table:headless',
                'GitHub tables require a header row; an empty one was added.',
                $table->span(),
            ));
            $headerCells = array_fill(0, $columns, '');
        } else {
            $headerCells = $this->tableRowCells($head[0], $columns);

            if (\count($head) > 1) {
                $this->issue(ConversionIssue::lossy(
                    'table:head-rows',
                    'GitHub tables have a single header row; extra head rows moved to the body.',
                    $table->span(),
                ));
                $body = [...\array_slice($head, 1), ...$body];
            }
        }

        $lines = [$this->tableLine($headerCells)];
        $lines[] = $this->tableLine(array_fill(0, $columns, '---'));

        foreach ($body as $row) {
            $lines[] = $this->tableLine($this->tableRowCells($row, $columns));
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $cells
     */
    private function tableLine(array $cells): string
    {
        return '| ' . implode(' | ', array_map(static fn(string $cell): string => str_replace("\n", ' ', $cell), $cells)) . ' |';
    }

    /**
     * @return list<string>
     */
    private function tableRowCells(TableRow $row, int $columns): array
    {
        $cells = [];

        foreach ($row->children() as $cell) {
            $cells[] = $this->tableCellText($cell);

            for ($extra = 1; $extra < $cell->colspan; ++$extra) {
                $cells[] = '';
            }

            if ($cell->colspan > 1 || $cell->rowspan > 1) {
                $this->issue(ConversionIssue::lossy(
                    'table:cell-span',
                    'GitHub tables cannot join cells; the span was flattened.',
                    $cell->span(),
                ));
            }
        }

        while (\count($cells) < $columns) {
            $cells[] = '';
        }

        return \array_slice($cells, 0, $columns);
    }

    private function tableCellText(TableCell $cell): string
    {
        $children = $cell->children();

        if ([] === $children) {
            return '';
        }

        $parts = [];
        $blockContent = false;

        foreach ($children as $child) {
            if ($child instanceof Paragraph) {
                $parts[] = $this->inlineFromText($child->text);

                continue;
            }

            $blockContent = true;
            $parts[] = str_replace("\n", ' ', implode(' ', $this->blocks([$child])));
        }

        if ($blockContent || \count($parts) > 1) {
            $this->issue(ConversionIssue::lossy(
                'table:block-cell',
                'GitHub table cells hold a single line; block content was flattened.',
                $cell->span(),
            ));
        }

        return str_replace('|', '\\|', implode(' ', $parts));
    }

    /**
     * @return list<string>
     */
    private function directiveBlocks(Directive $directive): array
    {
        $name = strtolower($directive->name);
        $handler = $this->profile->directives->isEnabled($name)
            ? $this->profile->extensions->directiveHandler($name)
            : null;

        if (null !== $handler) {
            $result = $handler->convertToMarkdown(
                $directive,
                $this->source,
                $this->profile,
                $this->options,
                new DirectiveConversionContext($this->convertDirectiveBody(...)),
            );
            array_push($this->issues, ...$result->report->issues);
            $output = rtrim($result->output, "\n");

            return '' === $output ? [] : [$output];
        }

        if ('include' === $name && null !== $this->options->fileAccessPolicy) {
            return $this->includeBlocks($directive);
        }

        if (isset(self::CODE_DIRECTIVES[$name])) {
            return [$this->codeBlockDirective($directive)];
        }

        if (isset(AdmonitionStyle::githubAlertNames()[$name]) || isset(self::NEAREST_ALERT[$name])) {
            return [$this->admonitionBlock($directive, $name)];
        }

        if (isset(self::VERSION_LABELS[$name])) {
            return [$this->versionBlock($directive, $name)];
        }

        if ('image' === $name) {
            return [$this->imageBlock($directive)];
        }

        if ('figure' === $name) {
            return $this->figureBlocks($directive);
        }

        if ('toctree' === $name) {
            return $this->toctreeBlocks($directive);
        }

        if ('sidebar' === $name || 'topic' === $name) {
            return [$this->asideBlock($directive, $name)];
        }

        if ('class' === $name) {
            $this->issue(ConversionIssue::lossy(
                'directive:class',
                \sprintf(
                    'CSS class "%s" was dropped because Markdown has no block class syntax.',
                    trim($directive->arguments[0] ?? ''),
                ),
                $directive->span(),
            ));

            return [];
        }

        if ('raw' === $name && $this->options->allowRawHtml) {
            return [$this->rawBlock($directive)];
        }

        return [$this->unsupportedDirectiveBlock($directive, $name)];
    }

    /**
     * @return list<string>
     */
    private function includeBlocks(Directive $directive): array
    {
        $policy = $this->options->fileAccessPolicy
            ?? throw new \LogicException('Include conversion requires a file access policy.');
        $path = trim($directive->arguments[0] ?? '');

        if ([] !== $directive->options) {
            $this->issue(ConversionIssue::unsupported(
                'directive:include',
                \sprintf(
                    'Include options are not implemented: %s.',
                    implode(', ', array_keys($directive->options)),
                ),
                $directive->span(),
            ));

            return [$this->placeholder('directive include options')];
        }

        try {
            $included = $policy->read($path, $this->sourcePath);
        } catch (FileReadException $exception) {
            $this->issue(ConversionIssue::unsupported(
                'directive:include',
                $exception->getMessage(),
                $directive->span(),
            ));

            return [$this->placeholder('directive include')];
        }

        $stack = [] === $this->includeStack && null !== $this->sourcePath
            ? [$this->sourcePath]
            : $this->includeStack;

        if (\in_array($included->path, $stack, true)) {
            $this->issue(ConversionIssue::unsupported(
                'directive:include',
                \sprintf('Include cycle detected at "%s".', $included->path),
                $directive->span(),
            ));

            return [$this->placeholder('directive include cycle')];
        }

        if (\count($stack) >= $policy->maxIncludeDepth) {
            $this->issue(ConversionIssue::unsupported(
                'directive:include',
                \sprintf(
                    'Include depth exceeds the configured limit of %d.',
                    $policy->maxIncludeDepth,
                ),
                $directive->span(),
            ));

            return [$this->placeholder('directive include depth')];
        }

        $source = Source::fromString($included->bytes);
        $parsed = new BlockParser()->parse($source, $this->profile);

        if ($parsed->problems()->hasAtLeast(ProblemSeverity::Error)) {
            $codes = array_values(array_unique(array_map(
                static fn(Problem $problem): string => $problem->code,
                $parsed->problems()->filterBySeverity(ProblemSeverity::Error)->problems(),
            )));
            $this->issue(ConversionIssue::unsupported(
                'directive:include',
                \sprintf(
                    'Included "%s" contains parser errors: %s.',
                    $included->path,
                    implode(', ', $codes),
                ),
                $directive->span(),
            ));
        }

        $writer = new self(
            $parsed->document(),
            $source,
            $this->profile,
            $this->options,
            $this->targets,
            $parsed->references(),
            $this->projectReferences,
            $included->path,
            [...$stack, $included->path],
            true,
            $this->documentPath,
        );
        $blocks = $writer->documentBlocks();
        $this->issue(ConversionIssue::lossy(
            'directive:include',
            \sprintf('Included "%s" was expanded; its file boundary and include relationship were dropped.', $included->path),
            $directive->span(),
        ));

        foreach ($writer->issues as $issue) {
            $this->issue(new ConversionIssue(
                $issue->construct,
                $issue->message,
                $issue->kind,
                $directive->span(),
            ));
        }

        return $blocks;
    }

    /**
     * Indexes section and explicit targets from authorized includes before
     * any parent content is written. This mirrors the logical insertion
     * performed by docutils while leaving file access closed by default.
     *
     * @param list<string> $stack
     */
    private function indexIncludedTargets(Document $document, ?string $sourcePath, array $stack): void
    {
        $policy = $this->options->fileAccessPolicy;
        if (null === $policy || \count($stack) >= $policy->maxIncludeDepth) {
            return;
        }

        foreach ($document->descendants() as $node) {
            if (
                !$node instanceof Directive
                || 'include' !== strtolower($node->name)
                || [] !== $node->options
            ) {
                continue;
            }

            try {
                $included = $policy->read(trim($node->arguments[0] ?? ''), $sourcePath);
            } catch (FileReadException) {
                continue;
            }

            if (
                \in_array($included->path, $stack, true)
                || isset($this->indexedIncludes[$included->path])
            ) {
                continue;
            }

            $this->indexedIncludes[$included->path] = true;
            $includedSource = Source::fromString($included->bytes);
            $parsed = new BlockParser()->parse($includedSource, $this->profile);
            $this->targets->addDocument($parsed->document(), $parsed->references());
            $this->indexIncludedTargets(
                $parsed->document(),
                $included->path,
                [...$stack, $included->path],
            );
        }
    }

    private function codeBlockDirective(Directive $directive): string
    {
        $language = strtolower(trim($directive->arguments[0] ?? ''));

        if ([] !== $directive->options) {
            $this->issue(ConversionIssue::lossy(
                'code-block:options',
                sprintf('Code block options dropped: %s.', implode(', ', array_keys($directive->options))),
                $directive->span(),
            ));
        }

        $content = null === $directive->rawBody ? '' : Lines::dedent($this->source->slice($directive->rawBody));

        return $this->fencedBlock($language, $content);
    }

    private function fencedBlock(string $language, string $content): string
    {
        $char = $this->options->fenceStyle->value;
        $longest = 0;

        if (false !== preg_match_all('/' . preg_quote($char, '/') . '+/', $content, $matches)) {
            foreach ($matches[0] as $run) {
                $longest = max($longest, \strlen($run));
            }
        }

        $fence = $this->options->fenceStyle->fence($longest + 1);
        $body = '' === $content ? '' : $content . "\n";

        return $fence . $language . "\n" . $body . $fence;
    }

    private function admonitionBlock(Directive $directive, string $name): string
    {
        $alertNames = AdmonitionStyle::githubAlertNames();
        $alert = $alertNames[$name] ?? self::NEAREST_ALERT[$name];

        if (!isset($alertNames[$name])) {
            $this->issue(ConversionIssue::approximated(
                'directive:' . $name,
                sprintf('Admonition "%s" rendered as the nearest GitHub alert, "%s".', $name, $alert),
                $directive->span(),
            ));
        }

        if ([] !== $directive->options) {
            $this->issue(ConversionIssue::lossy(
                'directive:' . $name . ':options',
                sprintf('Admonition options dropped: %s.', implode(', ', array_keys($directive->options))),
                $directive->span(),
            ));
        }

        $blocks = [];

        if (AdmonitionStyle::GithubAlert === $this->options->admonitionStyle) {
            $blocks[] = '[!' . $alert . ']';
        } else {
            $blocks[] = '**' . ucfirst(strtolower($alert)) . '**';
        }

        $title = trim($directive->arguments[0] ?? '');

        if ('' !== $title) {
            $blocks[] = '**' . $this->escapeText($title) . '**';
        }

        return Lines::quote(implode("\n\n", [...$blocks, ...$this->nestedBlocks($directive)]));
    }

    private function versionBlock(Directive $directive, string $name): string
    {
        $version = trim($directive->arguments[0] ?? '');
        $label = rtrim(sprintf(self::VERSION_LABELS[$name], $version));
        $blocks = ['**' . $label . '**', ...$this->nestedBlocks($directive)];

        return Lines::quote(implode("\n\n", $blocks));
    }

    /**
     * @return list<string>
     */
    private function toctreeBlocks(Directive $directive): array
    {
        $body = null === $directive->rawBody
            ? ''
            : Lines::dedent($this->source->slice($directive->rawBody));
        $split = preg_split('/\R/', $body);
        $entries = array_values(array_filter(
            array_map('trim', false === $split ? [] : $split),
            static fn(string $entry): bool => '' !== $entry,
        ));
        $targets = [];

        foreach ($entries as $entry) {
            [$title, $docname] = $this->toctreeEntry($entry);
            $wildcard = false !== strpbrk($docname, '*?[');

            if ($wildcard) {
                if (!array_key_exists('glob', $directive->options)) {
                    $this->issue(ConversionIssue::unsupported(
                        'directive:toctree',
                        \sprintf('Toctree wildcard "%s" requires the :glob: option.', $docname),
                        $directive->span(),
                    ));

                    return [$this->placeholder('directive toctree glob')];
                }

                if (null === $this->projectReferences) {
                    $this->issue(ConversionIssue::unsupported(
                        'directive:toctree',
                        'Toctree glob entries require a project reference map.',
                        $directive->span(),
                    ));

                    return [$this->placeholder('directive toctree glob')];
                }

                $pattern = $this->toctreeTargetPath($docname);
                $matched = false;

                foreach ($this->projectReferences->documentPaths() as $path) {
                    if (
                        $path !== self::canonicalPath($this->documentPath ?? '')
                        && fnmatch($pattern, $path, \FNM_PATHNAME)
                    ) {
                        $targets[$path] ??= null;
                        $matched = true;
                    }
                }

                if (!$matched) {
                    $this->issue(ConversionIssue::unsupported(
                        'directive:toctree',
                        \sprintf('Toctree glob "%s" matches no project document.', $docname),
                        $directive->span(),
                    ));

                    return [$this->placeholder('directive toctree glob')];
                }

                continue;
            }

            if (null === $this->projectReferences) {
                $this->issue(ConversionIssue::unsupported(
                    'directive:toctree',
                    'Toctree entries require a project reference map.',
                    $directive->span(),
                ));

                return [$this->placeholder('directive toctree')];
            }

            $target = $this->toctreeTargetPath($docname);
            $resolved = $this->projectReferences->resolveDocumentPath($target);

            if (null === $resolved) {
                $this->issue(ConversionIssue::unsupported(
                    'directive:toctree',
                    \sprintf('Toctree entry "%s" matches no project document.', $docname),
                    $directive->span(),
                ));

                return [$this->placeholder('directive toctree target')];
            }

            $targets[$resolved] ??= $title;
        }

        if ([] === $targets) {
            $this->issue(ConversionIssue::unsupported(
                'directive:toctree',
                'Toctree has no concrete document entries to map.',
                $directive->span(),
            ));

            return [$this->placeholder('directive toctree')];
        }

        $this->issue(ConversionIssue::lossy(
            'directive:toctree',
            'Toctree navigation was flattened into a Markdown link list.',
            $directive->span(),
        ));
        $lines = [];

        foreach ($targets as $path => $title) {
            $display = $title
                ?? $this->projectReferences?->documentTitleFor($path)
                ?? str_replace('_', ' ', basename($path));
            $target = null === $this->documentPath
                ? $path . '.md'
                : $this->relativeMarkdownPath($this->documentPath, $path);
            $lines[] = '- [' . $this->escapeText($display) . '](' . $target . ')';
        }

        return [implode("\n", $lines)];
    }

    /**
     * @return array{?string, string}
     */
    private function toctreeEntry(string $entry): array
    {
        if (1 === preg_match('/^(.*?)\s*<([^<>]+)>$/', $entry, $matches)) {
            $title = trim($matches[1]);

            return ['' === $title ? null : $title, trim($matches[2])];
        }

        return [null, $entry];
    }

    private function toctreeTargetPath(string $docname): string
    {
        $docname = str_replace('\\', '/', trim($docname));

        if (!str_starts_with($docname, '/') && null !== $this->documentPath && str_contains($this->documentPath, '/')) {
            $docname = substr($this->documentPath, 0, (int) strrpos($this->documentPath, '/')) . '/' . $docname;
        }

        $parts = [];

        foreach (explode('/', ltrim($docname, '/')) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }

            if ('..' === $part) {
                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return self::canonicalPath(implode('/', $parts));
    }

    private function asideBlock(Directive $directive, string $name): string
    {
        $title = trim($directive->arguments[0] ?? '');
        $label = '' === $title ? ucfirst($name) : $title;
        $this->issue(ConversionIssue::approximated(
            'directive:' . $name,
            \sprintf('Directive "%s" was flattened into a quoted Markdown aside.', $name),
            $directive->span(),
        ));

        if ([] !== $directive->options) {
            $this->issue(ConversionIssue::lossy(
                'directive:' . $name . ':options',
                sprintf('Aside options dropped: %s.', implode(', ', array_keys($directive->options))),
                $directive->span(),
            ));
        }

        return Lines::quote(implode("\n\n", [
            '**' . $this->escapeText($label) . '**',
            ...$this->nestedBlocks($directive),
        ]));
    }

    private function rawBlock(Directive $directive): string
    {
        $format = strtolower(trim($directive->arguments[0] ?? ''));

        if (
            'html' !== $format
            || [] !== $directive->options
            || null === $directive->rawBody
        ) {
            $this->issue(ConversionIssue::unsupported(
                'directive:raw',
                'Only inline raw HTML without options can be embedded in Markdown.',
                $directive->span(),
            ));

            return $this->placeholder('directive raw');
        }

        $this->issue(ConversionIssue::approximated(
            'directive:raw',
            'Raw HTML was embedded directly in the Markdown output under explicit raw HTML authority.',
            $directive->span(),
        ));

        return Lines::dedent($this->source->slice($directive->rawBody));
    }

    private function imageBlock(Directive $directive): string
    {
        $url = trim($directive->arguments[0] ?? '');

        if ('' === $url) {
            $this->issue(ConversionIssue::unsupported(
                'directive:image',
                'Image directive without a URI cannot be converted.',
                $directive->span(),
            ));

            return $this->placeholder('image');
        }

        $dropped = array_values(array_intersect(array_keys($directive->options), self::DROPPED_IMAGE_OPTIONS));

        if ([] !== $dropped) {
            $this->issue(ConversionIssue::lossy(
                'directive:image',
                sprintf('Image options dropped: %s.', implode(', ', $dropped)),
                $directive->span(),
            ));
        }

        $alt = trim($directive->options['alt'] ?? '');
        $image = '![' . $this->escapeText($alt) . '](' . $this->linkDestination($url) . ')';
        $target = trim($directive->options['target'] ?? '');

        if ('' !== $target) {
            return '[' . $image . '](' . $this->linkDestination($target) . ')';
        }

        return $image;
    }

    /**
     * @return list<string>
     */
    private function figureBlocks(Directive $directive): array
    {
        $this->issue(ConversionIssue::approximated(
            'directive:figure',
            'Figure rendered as an image followed by its caption.',
            $directive->span(),
        ));

        $dropped = array_values(array_intersect(array_keys($directive->options), ['figwidth', 'figclass']));

        if ([] !== $dropped) {
            $this->issue(ConversionIssue::lossy(
                'directive:figure:options',
                sprintf('Figure options dropped: %s.', implode(', ', $dropped)),
                $directive->span(),
            ));
        }

        return [$this->imageBlock($directive), ...$this->nestedBlocks($directive)];
    }

    private function unsupportedDirectiveBlock(Directive $directive, string $name): string
    {
        $known = $this->profile->directives->has($name);

        $this->issue(ConversionIssue::unsupported(
            'directive:' . $name,
            $known
                ? sprintf('Directive "%s" has no Markdown mapping.', $name)
                : sprintf('Directive "%s" is not recognized by the "%s" profile and has no Markdown mapping.', $name, $this->profile->name),
            $directive->span(),
        ));

        return $this->placeholder('directive ' . $name);
    }

    /**
     * Converts typed block children directly. The source-level fallback is
     * retained for legacy manually constructed directives that do not carry
     * the parsed body contract.
     *
     * @return list<string>
     */
    private function nestedBlocks(Directive $directive): array
    {
        if (DirectiveBodyKind::Blocks === $directive->bodyKind) {
            $blocks = $this->blocks($directive->children());
            $this->markDirectiveContextResolved($directive);

            return $blocks;
        }

        if (null === $directive->rawBody) {
            return [];
        }

        $text = Lines::dedent($this->source->slice($directive->rawBody));
        $subSource = Source::fromString($text);
        $parsed = new BlockParser()->parse($subSource, $this->profile);
        $writer = new self(
            $parsed->document(),
            $subSource,
            $this->profile,
            $this->options,
            $this->targets,
            $parsed->references(),
            $this->projectReferences,
            $this->sourcePath,
            $this->includeStack,
            $this->includedFragment,
            documentPath: $this->documentPath,
        );
        $blocks = $writer->documentBlocks();

        foreach ($writer->issues as $issue) {
            $this->issue(new ConversionIssue($issue->construct, $issue->message, $issue->kind, $directive->span()));
        }

        $this->markDirectiveContextResolved($directive);

        return $blocks;
    }

    private function convertDirectiveBody(Directive $directive): ConversionResult
    {
        $issueOffset = \count($this->issues);
        $blocks = $this->nestedBlocks($directive);
        $issues = \array_splice($this->issues, $issueOffset);
        $output = implode("\n\n", $blocks);

        return new ConversionResult(
            '' === $output ? '' : $output . "\n",
            new ConversionReport($issues),
        );
    }

    private function markDirectiveContextResolved(Directive $directive): void
    {
        if (null === $directive->rawBody || null === $this->references) {
            return;
        }

        foreach ($this->references->references(ReferenceType::Hyperlink) as $reference) {
            if (
                ReferenceStatus::Resolved === $reference->status
                || $reference->span->start < $directive->rawBody->start
                || $reference->span->end() > $directive->rawBody->end()
            ) {
                continue;
            }

            if (
                null === $this->targets->urlFor($reference->label)
                && null === $this->targets->sectionTitleFor($reference->label)
                && null === $this->targets->anchorFor($reference->label)
            ) {
                continue;
            }

            $this->resolvedReferenceSpans[ReferenceGraph::spanKey($reference->span)] = $reference->span;
        }
    }

    private function commentBlock(Comment $comment): string
    {
        $text = trim($comment->text);

        if (str_contains($text, '--')) {
            $this->issue(ConversionIssue::lossy(
                'node:Comment',
                'Comment text contains "--"; hyphen runs collapsed to keep the HTML comment valid.',
                $comment->span(),
            ));
            $text = (string) preg_replace('/-{2,}/', '-', $text);
        }

        return '' === $text ? '<!-- -->' : '<!-- ' . $text . ' -->';
    }

    /**
     * @return list<string>
     */
    private function targetBlocks(HyperlinkTarget $target): array
    {
        if ($target->anonymous) {
            return [];
        }

        $anchor = $this->targets->anchorFor($target->name);

        if (null === $anchor) {
            return [];
        }

        $this->issue(ConversionIssue::approximated(
            'target:internal',
            sprintf('Internal target "%s" written as an HTML anchor.', $target->name),
            $target->span(),
        ));

        return ['<a id="' . $anchor . '"></a>'];
    }

    private function footnoteBlock(FootnoteDefinition $footnote): string
    {
        $definition = $this->definitionForNode($footnote, DefinitionKind::Footnote);

        if (null === $definition) {
            return $this->fallbackBlocks($footnote)[0];
        }

        $blocks = $this->blocks($footnote->children());
        $id = $this->footnoteId($definition);

        if ([] === $blocks) {
            return '[^' . $id . ']:';
        }

        $first = array_shift($blocks);
        $output = Lines::prefix($first, '[^' . $id . ']: ', '    ');

        foreach ($blocks as $block) {
            $output .= "\n\n" . Lines::indent($block, '    ');
        }

        return $output;
    }

    private function citationBlock(CitationDefinition $citation): string
    {
        $definition = $this->definitionForNode($citation, DefinitionKind::Citation);

        if (null === $definition) {
            return $this->fallbackBlocks($citation)[0];
        }

        $anchor = $this->citationAnchor($definition);
        $body = implode("\n\n", $this->blocks($citation->children()));
        $label = '**[' . $this->escapeText($citation->label) . ']**';

        $this->issue(ConversionIssue::approximated(
            'citation',
            'Citation was written as an anchored Markdown paragraph.',
            $citation->span(),
        ));

        return '<a id="' . $anchor . '"></a>' . "\n\n" . $label . ('' === $body ? '' : ' ' . $body);
    }

    private function definitionForNode(Node $node, DefinitionKind $kind): ?ReferenceDefinition
    {
        foreach ($this->references?->definitions($kind) ?? [] as $definition) {
            if ($definition->node === $node) {
                return $definition;
            }
        }

        return null;
    }

    private function footnoteId(ReferenceDefinition $definition): string
    {
        return $this->footnoteIds[spl_object_id($definition)] ?? 'fn';
    }

    private function citationAnchor(ReferenceDefinition $definition): string
    {
        $fragmentPrefix = $this->fragmentAnchorPrefix();
        $prefix = null === $fragmentPrefix ? '' : $fragmentPrefix . '-';
        $slug = TargetMap::slug('citation-' . $prefix . $definition->name);

        return '' === $slug ? 'citation' : $slug;
    }

    private function fragmentAnchorPrefix(): ?string
    {
        if (!$this->includedFragment || null === $this->sourcePath) {
            return null;
        }

        $slug = TargetMap::slug(strtr($this->sourcePath, ['/' => '-', '\\' => '-', '.' => '-']));

        return ('' === $slug ? 'include' : $slug) . '-' . substr(hash('sha256', $this->sourcePath), 0, 8);
    }

    /**
     * @return list<string>
     */
    private function fallbackBlocks(Node $node): array
    {
        $class = $node::class;
        $short = false === ($position = strrpos($class, '\\')) ? $class : substr($class, $position + 1);

        $this->issue(ConversionIssue::unsupported(
            'node:' . $short,
            sprintf('Node "%s" has no Markdown mapping.', $short),
            $node->span(),
        ));

        return [$this->placeholder('node ' . $short)];
    }

    /**
     * The conservative degradation for constructs without a mapping: an
     * inert, greppable HTML comment.
     */
    private function placeholder(string $label): string
    {
        $safe = (string) preg_replace('/-{2,}/', '-', $label);

        return '<!-- rst: ' . trim($safe) . ' -->';
    }

    private function inlineFromText(Text $text): string
    {
        $sourceSegments = $text->sourceSegments();

        if (1 === \count($sourceSegments)) {
            $nodes = $this->inlineParser->parse($text->text, $text->span()->start);

            return $this->renderInline($nodes);
        }

        $nodes = [];

        foreach (explode("\n", $text->text) as $index => $segment) {
            $trimmed = trim($segment);

            if ('' === $trimmed) {
                continue;
            }

            $lead = \strlen($segment) - \strlen(ltrim($segment));

            if ([] !== $nodes) {
                $nodes[] = new InlineText(ByteSpan::of($sourceSegments[$index]->start + $lead, 0), ' ');
            }

            foreach ($this->inlineParser->parse($trimmed, $sourceSegments[$index]->start + $lead) as $node) {
                $nodes[] = $node;
            }
        }

        return $this->renderInline($nodes);
    }

    /**
     * @param list<Node> $nodes
     */
    private function renderInline(array $nodes): string
    {
        $markdown = '';

        foreach ($nodes as $node) {
            $markdown .= $this->renderInlineNode($node);
        }

        return $markdown;
    }

    private function renderInlineNode(Node $node): string
    {
        return match (true) {
            $node instanceof InlineText => $this->escapeText($node->text),
            $node instanceof Emphasis => '*' . $this->renderInline($node->children()) . '*',
            $node instanceof Strong => '**' . $this->renderInline($node->children()) . '**',
            $node instanceof InlineLiteral => $this->codeSpan($node->text),
            $node instanceof HyperlinkReference => $this->renderLink($node),
            $node instanceof StandaloneHyperlink => '<' . $node->uri . '>',
            $node instanceof InterpretedText => $this->renderRole($node),
            $node instanceof FootnoteReference => $this->renderFootnoteReference($node),
            $node instanceof CitationReference => $this->renderCitationReference($node),
            $node instanceof SubstitutionReference => $this->renderSubstitutionReference($node),
            $node instanceof InlineTarget => $this->renderInert($node, 'inline:target', $node->name, 'Inline target'),
            default => '',
        };
    }

    private function renderInert(Node $node, string $construct, string $text, string $label): string
    {
        $this->issue(ConversionIssue::lossy(
            $construct,
            sprintf('%s semantics were dropped and its source text was kept.', $label),
            $node->span(),
        ));

        return $this->escapeText($text);
    }

    private function renderFootnoteReference(FootnoteReference $node): string
    {
        $reference = $this->references?->referenceAt($node->span(), ReferenceType::Footnote);

        if (
            null === $reference
            || ReferenceStatus::Resolved !== $reference->status
            || null === $reference->target
        ) {
            return $this->renderInert(
                $node,
                'inline:footnote-reference',
                '[' . $node->label . ']',
                'Footnote reference',
            );
        }

        return '[^' . $this->footnoteId($reference->target) . ']';
    }

    private function renderCitationReference(CitationReference $node): string
    {
        $reference = $this->references?->referenceAt($node->span(), ReferenceType::Citation);

        if (
            null === $reference
            || ReferenceStatus::Resolved !== $reference->status
            || null === $reference->target
        ) {
            return $this->renderInert(
                $node,
                'inline:citation-reference',
                '[' . $node->label . ']',
                'Citation reference',
            );
        }

        return '[' . $this->escapeText($node->label) . '](#' . $this->citationAnchor($reference->target) . ')';
    }

    private function renderSubstitutionReference(SubstitutionReference $node): string
    {
        $reference = $this->references?->referenceAt($node->span(), ReferenceType::Substitution);
        $target = $reference?->target;

        if (
            null === $target
            || ReferenceStatus::Resolved !== $reference->status
            || null === $target->destination
            || null === $target->substitutionKind
        ) {
            return $this->renderInert(
                $node,
                'inline:substitution-reference',
                '|' . $node->name . '|',
                'Substitution reference',
            );
        }

        if (SubstitutionKind::Replace === $target->substitutionKind && null === $target->destinationSpan) {
            return $this->escapeText($target->destination);
        }

        return match ($target->substitutionKind) {
            SubstitutionKind::Unicode => $this->escapeText($target->destination),
            SubstitutionKind::Image => '!['
                . $this->escapeText($target->substitutionAlt ?? $target->destination)
                . '](' . $this->linkDestination($target->destination) . ')',
            SubstitutionKind::Replace => $this->renderInline($this->inlineParser->parse(
                $target->destination,
                $target->destinationSpan->start,
            )),
        };
    }

    private function renderLink(HyperlinkReference $reference): string
    {
        $text = $this->escapeText($reference->text);

        if (null !== $reference->embeddedUri) {
            return $this->emitResolvedLink($reference->text, $text, $reference->embeddedUri);
        }

        if ($reference->anonymous) {
            $url = $this->targets->takeAnonymousUrl();

            if (null === $url) {
                $this->issue(ConversionIssue::approximated(
                    'link:anonymous',
                    sprintf('Anonymous reference "%s" has no paired target; a reference link was written.', $reference->text),
                    $reference->span(),
                ));

                return '[' . $text . '][' . $text . ']';
            }

            return '[' . $text . '](' . $this->linkDestination($url) . ')';
        }

        $url = $this->targets->urlFor($reference->text);

        if (null !== $url) {
            $this->markContextResolved($reference);

            if (LinkStyle::Inline === $this->options->linkStyle) {
                return '[' . $text . '](' . $this->linkDestination($url) . ')';
            }

            return '[' . $text . '][' . $text . ']';
        }

        $sectionTitle = $this->targets->sectionTitleFor($reference->text);

        if (null !== $sectionTitle) {
            $this->markContextResolved($reference);

            return '[' . $text . '](#' . TargetMap::slug($sectionTitle) . ')';
        }

        $anchor = $this->targets->anchorFor($reference->text);

        if (null !== $anchor) {
            $this->markContextResolved($reference);

            return '[' . $text . '](#' . $anchor . ')';
        }

        $this->issue(ConversionIssue::approximated(
            'link:unresolved',
            sprintf('Reference "%s" has no target in this document; a reference link was written.', $reference->text),
            $reference->span(),
        ));

        return '[' . $text . '][' . $text . ']';
    }

    private function markContextResolved(HyperlinkReference $reference): void
    {
        $sourceReference = $this->references?->referenceAt($reference->span(), ReferenceType::Hyperlink);

        if (null === $sourceReference || ReferenceStatus::Resolved === $sourceReference->status) {
            return;
        }

        $span = $reference->span();
        $this->resolvedReferenceSpans[$span->start . ':' . $span->length] = $span;
    }

    /**
     * Writes a link whose destination is already known, honoring the
     * configured link style.
     */
    private function emitResolvedLink(string $rawText, string $text, string $url): string
    {
        if (LinkStyle::Reference === $this->options->linkStyle && $this->registerDefinition($rawText, $url)) {
            return '[' . $text . '][' . $text . ']';
        }

        return '[' . $text . '](' . $this->linkDestination($url) . ')';
    }

    private function registerDefinition(string $label, string $url): bool
    {
        $key = TargetMap::normalize($label);

        foreach ([...$this->targets->definitions(), ...$this->extraDefinitions] as $definition) {
            if (TargetMap::normalize($definition['label']) === $key) {
                return $definition['url'] === $url;
            }
        }

        $this->extraDefinitions[] = ['label' => $label, 'url' => $url];

        return true;
    }

    private function renderRole(InterpretedText $role): string
    {
        $name = strtolower($role->role ?? '');

        if ('' === $name) {
            $this->issue(ConversionIssue::approximated(
                'role:default',
                'Default-role interpreted text rendered as emphasis.',
                $role->span(),
            ));

            return '*' . $this->escapeText($role->text) . '*';
        }

        $spec = $this->profile->roles->get($name);
        $handler = null === $spec ? null : $this->profile->extensions->roleHandler($spec->name);

        if (null !== $handler) {
            $result = $handler->convertToMarkdown($role, $this->source, $this->profile, $this->options);
            array_push($this->issues, ...$result->report->issues);

            return $result->output;
        }

        if ('ref' === $name || 'doc' === $name) {
            return $this->renderCrossReference($role, $name);
        }

        $roleName = null === $spec ? $name : $spec->name;

        $this->issue(
            \in_array($roleName, self::CODE_LIKE_ROLES, true)
                ? ConversionIssue::approximated(
                    'role:' . $name,
                    sprintf('Code-like role "%s" rendered as a code span.', $name),
                    $role->span(),
                )
                : ConversionIssue::lossy(
                    'role:' . $name,
                    sprintf('Role "%s" semantics were dropped and its text was rendered as a code span.', $name),
                    $role->span(),
                ),
        );

        return $this->codeSpan($role->text);
    }

    private function renderCrossReference(InterpretedText $role, string $name): string
    {
        $title = null;
        $label = trim($role->text);

        if (1 === preg_match('/^(.*)<([^<>]+)>$/', $label, $matches)) {
            $title = trim($matches[1]);
            $label = trim($matches[2]);
            $title = '' === $title ? null : $title;
        }

        $type = 'ref' === $name ? ReferenceType::SphinxRef : ReferenceType::SphinxDoc;
        $reference = $this->references?->referenceAt($role->span(), $type);
        $project = null === $reference || null === $this->projectReferences || null === $this->documentPath
            ? null
            : $this->projectReferences->resolveReference($this->documentPath, $reference);

        if (null !== $project && ReferenceStatus::Resolved === $project->status && null !== $project->targetPath) {
            $target = $this->relativeMarkdownPath($this->documentPath, $project->targetPath);
            $fragment = ReferenceType::SphinxRef === $type && null !== $project->target
                ? '#' . ReferenceName::id($project->target->name)
                : '';
            $href = $project->targetPath === self::canonicalPath($this->documentPath) ? $fragment : $target . $fragment;
            $display = $title ?? $project->displayLabel ?? $label;
            $this->issue(ConversionIssue::approximated(
                'role:' . $name,
                sprintf('Cross-reference "%s" resolved through the project map.', $label),
                $role->span(),
            ));

            return '[' . $this->escapeText($display) . '](' . $href . ')';
        }

        $sectionTitle = 'ref' === $name ? $this->targets->sectionTitleFor($label) : null;

        if (null !== $sectionTitle) {
            $this->issue(ConversionIssue::approximated(
                'role:' . $name,
                sprintf('Cross-reference "%s" mapped to the heading anchor of "%s".', $label, $sectionTitle),
                $role->span(),
            ));

            return '[' . $this->escapeText($title ?? $sectionTitle) . '](#' . TargetMap::slug($sectionTitle) . ')';
        }

        $anchor = 'ref' === $name ? $this->targets->anchorFor($label) : null;

        if (null !== $anchor) {
            $this->issue(ConversionIssue::approximated(
                'role:' . $name,
                sprintf('Cross-reference "%s" mapped to an HTML anchor.', $label),
                $role->span(),
            ));

            return '[' . $this->escapeText($title ?? $label) . '](#' . $anchor . ')';
        }

        $this->issue(ConversionIssue::lossy(
            'role:' . $name,
            sprintf('Cross-reference "%s" could not retain its external target; its text was kept.', $label),
            $role->span(),
        ));

        return $this->escapeText($title ?? $label);
    }

    private function relativeMarkdownPath(string $sourcePath, string $targetPath): string
    {
        $source = explode('/', self::canonicalPath($sourcePath));
        $target = explode('/', self::canonicalPath($targetPath));
        array_pop($source);

        while ([] !== $source && [] !== $target && $source[0] === $target[0]) {
            array_shift($source);
            array_shift($target);
        }

        return str_repeat('../', \count($source)) . implode('/', $target) . '.md';
    }

    private static function canonicalPath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');

        return str_ends_with($path, '.rst') ? substr($path, 0, -4) : $path;
    }

    private function codeSpan(string $text): string
    {
        $longest = 0;

        if (false !== preg_match_all('/`+/', $text, $matches)) {
            foreach ($matches[0] as $run) {
                $longest = max($longest, \strlen($run));
            }
        }

        $delimiter = str_repeat('`', $longest + 1);
        $pad = str_starts_with($text, '`') || str_ends_with($text, '`')
            || str_starts_with($text, ' ') || str_ends_with($text, ' ') ? ' ' : '';

        return $delimiter . $pad . $text . $pad . $delimiter;
    }

    /**
     * Wraps destinations Markdown cannot read bare.
     */
    private function linkDestination(string $url): string
    {
        if (str_contains($url, ' ') || str_contains($url, '(') || str_contains($url, ')')) {
            return '<' . str_replace(['<', '>'], ['\\<', '\\>'], $url) . '>';
        }

        return $url;
    }

    /**
     * Backslash-escapes the characters that would become Markdown markup.
     * Underscores stay bare inside words ("snake_case") where CommonMark
     * cannot emphasize anyway, keeping the output readable.
     */
    private function escapeText(string $text): string
    {
        $escaped = (string) preg_replace('/([\\\\`*\[\]<])/', '\\\\$1', $text);

        return (string) preg_replace('/(?<![A-Za-z0-9])_|_(?![A-Za-z0-9])/', '\\\\_', $escaped);
    }

    /**
     * Escapes a paragraph's first characters when they would reparse as a
     * block construct. Inline rendering already escaped every markup
     * character, so only characters that are inert inline can leak here.
     */
    private function escapeLineStart(string $line): string
    {
        if (1 === preg_match('/^(\d{1,9})([.)])(\s|$)/', $line, $matches)) {
            return $matches[1] . '\\' . $matches[2] . substr($line, \strlen($matches[1]) + 1);
        }

        if (1 === preg_match('/^([#>+-])(\s|$)/', $line)) {
            return '\\' . $line;
        }

        if (1 === preg_match('/^(-{3,}|_{3,})\s*$/', $line)) {
            return '\\' . $line;
        }

        return $line;
    }

    private function issue(ConversionIssue $issue): void
    {
        $this->issues[] = $issue;
    }
}
