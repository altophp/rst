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

namespace Alto\Rst\Tests\Convert;

use Alto\Rst\Convert\AdmonitionStyle;
use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\ConversionResult;
use Alto\Rst\Convert\HeadingStyle;
use Alto\Rst\Convert\IssueKind;
use Alto\Rst\Convert\LinkStyle;
use Alto\Rst\Convert\RstToMarkdown;
use Alto\Rst\Extension\DirectiveConversionContext;
use Alto\Rst\Extension\Symfony\ConfigurationBlockHandler;
use Alto\Rst\Extension\Symfony\ScreencastHandler;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\EnumeratedList;
use Alto\Rst\Node\EnumerationStyle;
use Alto\Rst\Node\ListItem;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Table;
use Alto\Rst\Node\TableCell;
use Alto\Rst\Node\TableRow;
use Alto\Rst\Node\TableStyle;
use Alto\Rst\Node\Text;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Reference\ProjectReferenceMap;
use Alto\Rst\Rst;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;
use Alto\Rst\Tests\Convert\Support\UnmappedNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\TestCase;

#[CoversNamespace('Alto\\Rst\\Convert')]
#[CoversClass(DirectiveConversionContext::class)]
#[CoversClass(ConfigurationBlockHandler::class)]
#[CoversClass(ScreencastHandler::class)]
final class RstToMarkdownTest extends TestCase
{
    private function convert(string $rst, ?ConversionOptions $options = null): ConversionResult
    {
        $source = Source::fromString($rst);
        $document = new BlockParser()->parse($source)->document();

        return new RstToMarkdown()->convert($document, $source, Profile::symfony(), $options);
    }

    public function testAnEmptyDocumentConvertsToAnEmptyString(): void
    {
        $result = $this->convert('');

        self::assertSame('', $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testSectionsBecomeAtxHeadingsByResolvedLevel(): void
    {
        $rst = <<<'RST'
            Title
            =====

            Intro.

            Sub
            ---

            Body.
            RST;

        $result = $this->convert($rst);

        self::assertSame("# Title\n\nIntro.\n\n## Sub\n\nBody.\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testSetextHeadingStyleFallsBackToAtxBelowLevelTwo(): void
    {
        $rst = <<<'RST'
            Title
            =====

            Sub
            ---

            Deep
            ~~~~

            Body.
            RST;

        $result = $this->convert($rst, new ConversionOptions(headingStyle: HeadingStyle::Setext));

        self::assertSame("Title\n=====\n\nSub\n---\n\n### Deep\n\nBody.\n", $result->output);
    }

    public function testInlineMarkupMapsToMarkdown(): void
    {
        $result = $this->convert('Use *this* and **that** with ``code()``.');

        self::assertSame("Use *this* and **that** with `code()`.\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testMarkupCharactersInPlainTextAreEscaped(): void
    {
        $result = $this->convert('Star \* bracket [x] under _lead but snake_case stays.');

        self::assertSame(
            "Star \\* bracket \\[x\\] under \\_lead but snake_case stays.\n",
            $result->output,
        );
    }

    public function testReferenceStyleLinksStayReferenceStyle(): void
    {
        $rst = <<<'RST'
            See the Symfony_ docs.

            .. _Symfony: https://symfony.com
            RST;

        $result = $this->convert($rst);

        self::assertSame("See the [Symfony][Symfony] docs.\n\n[Symfony]: https://symfony.com\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testPhraseReferencesResolveThroughTheirTarget(): void
    {
        $rst = <<<'RST'
            Read `the manual`_ first.

            .. _the manual: https://example.com/manual
            RST;

        $result = $this->convert($rst);

        self::assertSame(
            "Read [the manual][the manual] first.\n\n[the manual]: https://example.com/manual\n",
            $result->output,
        );
    }

    public function testEmbeddedUriLinksBecomeInlineLinks(): void
    {
        $result = $this->convert('See `the docs <https://example.com>`_ now.');

        self::assertSame("See [the docs](https://example.com) now.\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testInlineLinkStyleInlinesReferenceLinksAndDropsDefinitions(): void
    {
        $rst = <<<'RST'
            See Symfony_.

            .. _Symfony: https://symfony.com
            RST;

        $result = $this->convert($rst, new ConversionOptions(linkStyle: LinkStyle::Inline));

        self::assertSame("See [Symfony](https://symfony.com).\n", $result->output);
    }

    public function testReferenceLinkStyleCollectsEmbeddedUrisAsDefinitions(): void
    {
        $result = $this->convert('See `the docs <https://example.com>`_ now.', new ConversionOptions(linkStyle: LinkStyle::Reference));

        self::assertSame("See [the docs][the docs] now.\n\n[the docs]: https://example.com\n", $result->output);
    }

    public function testAnonymousReferencesPairWithAnonymousTargetsInOrder(): void
    {
        $rst = <<<'RST'
            See `first link`__ and `second link`__.

            __ https://one.example.com
            __ https://two.example.com
            RST;

        $result = $this->convert($rst);

        self::assertSame(
            "See [first link](https://one.example.com) and [second link](https://two.example.com).\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testUnresolvedReferencesKeepAReferenceLinkAndReport(): void
    {
        $result = $this->convert('See elsewhere_.');

        self::assertSame("See [elsewhere][elsewhere].\n", $result->output);
        self::assertCount(1, $result->report->ofKind(IssueKind::Approximated));
        self::assertSame(['link:unresolved' => 1], $result->report->countsByConstruct());
    }

    public function testReferencesToSectionTitlesBecomeHeadingAnchorLinks(): void
    {
        $rst = <<<'RST'
            Installation Guide
            ==================

            Go back to `Installation Guide`_.
            RST;

        $result = $this->convert($rst);

        self::assertSame(
            "# Installation Guide\n\nGo back to [Installation Guide](#installation-guide).\n",
            $result->output,
        );
    }

    public function testStandaloneUrisBecomeAutolinks(): void
    {
        $result = $this->convert('Browse https://example.com/a today.');

        self::assertSame("Browse <https://example.com/a> today.\n", $result->output);
    }

    public function testCodeBlockDirectivesBecomeFencedBlocks(): void
    {
        $rst = <<<'RST'
            .. code-block:: yaml

                framework:
                    secret: '%env(APP_SECRET)%'
            RST;

        $result = $this->convert($rst);

        self::assertSame("```yaml\nframework:\n    secret: '%env(APP_SECRET)%'\n```\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testHtmlTwigFenceNameIsPreservedExactly(): void
    {
        $rst = <<<'RST'
            .. code-block:: html+twig

                {{ value }}
            RST;

        $result = $this->convert($rst);

        self::assertSame("```html+twig\n{{ value }}\n```\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testTerminalAndDiffFencesStayVerbatim(): void
    {
        $rst = <<<'RST'
            .. code-block:: terminal

                $ composer require alto/rst

            .. code-block:: diff

                -old
                +new
            RST;

        $result = $this->convert($rst);

        self::assertSame(
            "```terminal\n$ composer require alto/rst\n```\n\n```diff\n-old\n+new\n```\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testFenceGrowsBeyondBacktickRunsInTheContent(): void
    {
        $rst = <<<'RST'
            .. code-block:: markdown

                ```js
                let x = 1;
                ```
            RST;

        $result = $this->convert($rst);

        self::assertSame("````markdown\n```js\nlet x = 1;\n```\n````\n", $result->output);
    }

    public function testLiteralBlocksBecomePlainFences(): void
    {
        $rst = <<<'RST'
            Example::

                echo "hi"
            RST;

        $result = $this->convert($rst);

        self::assertSame("Example:\n\n```\necho \"hi\"\n```\n", $result->output);
    }

    public function testAdmonitionsBecomeGithubAlerts(): void
    {
        $rst = <<<'RST'
            .. note::

                Take *note* of this.
            RST;

        $result = $this->convert($rst);

        self::assertSame("> [!NOTE]\n>\n> Take *note* of this.\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testNonGithubAdmonitionsMapToTheNearestAlertWithAnIssue(): void
    {
        $rst = <<<'RST'
            .. seealso::

                The other chapter.
            RST;

        $result = $this->convert($rst);

        self::assertSame("> [!NOTE]\n>\n> The other chapter.\n", $result->output);
        self::assertSame(['directive:seealso' => 1], $result->report->countsByConstruct());
        self::assertCount(1, $result->report->ofKind(IssueKind::Approximated));
    }

    public function testBlockquoteAdmonitionStyleUsesABoldLabel(): void
    {
        $rst = <<<'RST'
            .. warning::

                Careful.
            RST;

        $result = $this->convert($rst, new ConversionOptions(admonitionStyle: AdmonitionStyle::Blockquote));

        self::assertSame("> **Warning**\n>\n> Careful.\n", $result->output);
    }

    public function testAdmonitionBodiesSeeTheDocumentTargets(): void
    {
        $rst = <<<'RST'
            .. tip::

                Try Symfony_.

            .. _Symfony: https://symfony.com
            RST;

        $result = $this->convert($rst);

        self::assertSame(
            "> [!TIP]\n>\n> Try [Symfony][Symfony].\n\n[Symfony]: https://symfony.com\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testVersionaddedBecomesABlockquotedVersionLine(): void
    {
        $rst = <<<'RST'
            .. versionadded:: 2.20

                The ability to do this was added.
            RST;

        $result = $this->convert($rst);

        self::assertSame("> **New in version 2.20**\n>\n> The ability to do this was added.\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testDeprecatedBecomesABlockquotedVersionLine(): void
    {
        $rst = <<<'RST'
            .. deprecated:: 2.9

                Use the other thing.
            RST;

        $result = $this->convert($rst);

        self::assertSame("> **Deprecated since version 2.9**\n>\n> Use the other thing.\n", $result->output);
    }

    public function testImagesBecomeMarkdownImages(): void
    {
        $rst = <<<'RST'
            .. image:: pictures/map.png
                :alt: A map
            RST;

        $result = $this->convert($rst);

        self::assertSame("![A map](pictures/map.png)\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testDroppedImageOptionsAreReported(): void
    {
        $rst = <<<'RST'
            .. image:: map.png
                :alt: A map
                :width: 400
            RST;

        $result = $this->convert($rst);

        self::assertSame("![A map](map.png)\n", $result->output);
        self::assertSame(['directive:image' => 1], $result->report->countsByConstruct());
    }

    public function testSimpleTablesBecomePipeTables(): void
    {
        $rst = <<<'RST'
            =====  ========
            Name   Meaning
            =====  ========
            a      first
            b|c    second
            =====  ========
            RST;

        $result = $this->convert($rst);

        self::assertSame(
            "| Name | Meaning |\n| --- | --- |\n| a | first |\n| b\\|c | second |\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testBulletAndEnumeratedListsKeepMarkersAndNumbers(): void
    {
        $rst = <<<'RST'
            * one
            * two

            3. three
            4. four
            RST;

        $result = $this->convert($rst);

        self::assertSame("* one\n* two\n\n3. three\n4. four\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testMultiParagraphListItemsAreLooseAndIndented(): void
    {
        $rst = <<<'RST'
            - first

              more about first

            - second
            RST;

        $result = $this->convert($rst);

        self::assertSame("- first\n\n  more about first\n\n- second\n", $result->output);
    }

    public function testTransitionsBecomeThematicBreaks(): void
    {
        $result = $this->convert("before\n\n----\n\nafter\n");

        self::assertSame("before\n\n---\n\nafter\n", $result->output);
    }

    public function testCommentsBecomeHtmlComments(): void
    {
        $result = $this->convert(".. a private note\n");

        self::assertSame("<!-- a private note -->\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testUnknownDirectivesLeaveAPlaceholderAndAnUnsupportedIssue(): void
    {
        $rst = <<<'RST'
            .. unknown::

                page-one
            RST;

        $result = $this->convert($rst);

        self::assertSame("<!-- rst: directive unknown -->\n", $result->output);
        self::assertFalse($result->report->isLossless());
        self::assertSame(['directive:unknown' => 1], $result->report->countsByConstruct());
    }

    public function testToctreeGlobNeedsAProjectMap(): void
    {
        $result = $this->convert(".. toctree::\n    :glob:\n\n    guide/*\n");

        self::assertSame("<!-- rst: directive toctree glob -->\n", $result->output);
        self::assertCount(1, $result->report->ofKind(IssueKind::Unsupported));
    }

    public function testToctreeEntryNeedsAProjectMap(): void
    {
        $result = $this->convert(".. toctree::\n\n    guide/page\n");

        self::assertSame("<!-- rst: directive toctree -->\n", $result->output);
        self::assertCount(1, $result->report->ofKind(IssueKind::Unsupported));
    }

    public function testToctreeCanUseAProjectMapWithoutASourcePath(): void
    {
        $source = Source::fromString(".. toctree::\n\n    page\n");
        $parsed = Rst::sphinx()->parse($source->bytes);
        $page = Rst::sphinx()->parse("Page\n====\n");
        $map = new ProjectReferenceMap([
            'index.rst' => $parsed->references(),
            'page.rst' => $page->references(),
        ]);

        $result = new RstToMarkdown()->convert(
            $parsed->document(),
            $source,
            Profile::sphinx(),
            null,
            $parsed->references(),
            $map,
        );

        self::assertSame("- [Page](page.md)\n", $result->output);
        self::assertTrue($result->report->isComplete());
        self::assertFalse($result->report->isLossless());
    }

    public function testRefRolesResolveToHeadingAnchorsInsideTheDocument(): void
    {
        $rst = <<<'RST'
            .. _install:

            Install
            =======

            See :ref:`install` again.
            RST;

        $result = $this->convert($rst);

        self::assertSame("# Install\n\nSee [Install](#install) again.\n", $result->output);
        self::assertCount(1, $result->report->ofKind(IssueKind::Approximated));
        self::assertTrue($result->report->isLossless());
    }

    public function testRefRolesWithExplicitTitlesKeepTheTitle(): void
    {
        $result = $this->convert('See :ref:`the guide <other-file-label>`.');

        self::assertSame("See the guide.\n", $result->output);
        self::assertSame(['role:ref' => 1], $result->report->countsByConstruct());
        self::assertCount(1, $result->report->ofKind(IssueKind::Lossy));
        self::assertTrue($result->isComplete());
        self::assertFalse($result->isLossless());
    }

    public function testOtherRolesBecomeCodeSpansWithAnIssue(): void
    {
        $result = $this->convert('The :namespace:`Foo\\\\Bar` namespace.');

        self::assertStringContainsString('`', $result->output);
        self::assertSame(['role:namespace' => 1], $result->report->countsByConstruct());
        self::assertTrue($result->report->isLossless());
    }

    public function testSemanticRoleFallbackIsLossyButCodeLikeFallbackIsNot(): void
    {
        $math = $this->convert('Compute :math:`x^2`.');
        $command = $this->convert('Run :command:`composer install`.');

        self::assertSame("Compute `x^2`.\n", $math->output);
        self::assertCount(1, $math->report->ofKind(IssueKind::Lossy));
        self::assertTrue($math->isComplete());
        self::assertFalse($math->isLossless());

        self::assertSame("Run `composer install`.\n", $command->output);
        self::assertCount(1, $command->report->ofKind(IssueKind::Approximated));
        self::assertTrue($command->isLossless());
        self::assertFalse($command->isExact());
    }

    public function testBlockQuotesKeepTheirQuoting(): void
    {
        $rst = <<<'RST'
            paragraph

                quoted text
            RST;

        $result = $this->convert($rst);

        self::assertSame("paragraph\n\n> quoted text\n", $result->output);
    }

    public function testOutputAlwaysEndsWithASingleNewline(): void
    {
        $result = $this->convert("word\n\n\n");

        self::assertSame("word\n", $result->output);
    }

    public function testSectionsDeeperThanSixFlattenToLevelSix(): void
    {
        $rst = "A\n=\n\nB\n-\n\nC\n~\n\nD\n^\n\nE\n\"\n\nF\n'\n\nG\n+\n\nBody.\n";

        $result = $this->convert($rst);

        self::assertStringContainsString("###### F\n\n###### G\n\nBody.\n", $result->output);
        self::assertSame(['section:level' => 1], $result->report->countsByConstruct());
        self::assertCount(1, $result->report->ofKind(IssueKind::Lossy));
    }

    public function testUnicodeBulletMarkersAreRewrittenWithAnIssue(): void
    {
        $result = $this->convert("\u{2022} one\n\u{2022} two\n");

        self::assertSame("- one\n- two\n", $result->output);
        self::assertSame(['list:bullet-marker' => 1], $result->report->countsByConstruct());
        self::assertCount(1, $result->report->ofKind(IssueKind::Approximated));
    }

    public function testAnEmptyListItemWritesItsBareMarker(): void
    {
        $result = $this->convert("-\n- two\n");

        self::assertSame("-\n\n- two\n", $result->output);
    }

    public function testNonArabicEnumerationIsRewrittenAsArabicWithAnIssue(): void
    {
        $span = ByteSpan::of(0, 10);
        $item = new ListItem($span, [new Paragraph($span, new Text($span, 'first'))]);
        $document = new Document($span, [new EnumeratedList($span, EnumerationStyle::LowerAlpha, 1, [$item])]);

        $result = new RstToMarkdown()->convert($document, Source::fromString(''), Profile::symfony());

        self::assertSame("1. first\n", $result->output);
        self::assertSame(['list:enumeration-style' => 1], $result->report->countsByConstruct());
        self::assertSame(
            'Markdown only numbers with arabic digits; "loweralpha" numbering rewritten.',
            $result->report->ofKind(IssueKind::Lossy)[0]->message,
        );
    }

    public function testHeadlessSimpleTablesGainAnEmptyHeaderRow(): void
    {
        $result = $this->convert("=====  =====\none    two\n=====  =====\n");

        self::assertSame("|  |  |\n| --- | --- |\n| one | two |\n", $result->output);
        self::assertSame(['table:headless' => 1], $result->report->countsByConstruct());
        self::assertCount(1, $result->report->ofKind(IssueKind::Approximated));
        self::assertSame([], $result->report->ofKind(IssueKind::Lossy));
    }

    public function testExtraTableHeadRowsMoveToTheBody(): void
    {
        $rst = "=====  =====\nh1     h2\nh1b    h2b\n=====  =====\none    two\n=====  =====\n";

        $result = $this->convert($rst);

        self::assertSame(
            "| h1 | h2 |\n| --- | --- |\n| h1b | h2b |\n| one | two |\n",
            $result->output,
        );
        self::assertSame(['table:head-rows' => 1], $result->report->countsByConstruct());
    }

    public function testSpannedTableCellsAreFlattened(): void
    {
        $rst = "======  ======\nSpanning head\n--------------\none     two\n======  ======\n";

        $result = $this->convert($rst);

        self::assertStringContainsString("| Spanning head |  |\n| one | two |\n", $result->output);
        self::assertArrayHasKey('table:cell-span', $result->report->countsByConstruct());
    }

    public function testShortTableRowsArePaddedToTheColumnCount(): void
    {
        $result = $this->convert("=====  =====\nh1     h2\n=====  =====\nonly\n=====  =====\n");

        self::assertSame("| h1 | h2 |\n| --- | --- |\n| only |  |\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testBlockContentInTableCellsIsFlattened(): void
    {
        $rst = "=========  =========\nhead1      head2\n=========  =========\ncell with  - a\nlines      - b\n=========  =========\n";

        $result = $this->convert($rst);

        self::assertStringContainsString('| cell with | - a |', $result->output);
        self::assertArrayHasKey('table:block-cell', $result->report->countsByConstruct());
        self::assertTrue($result->report->isComplete());
        self::assertFalse($result->report->isLossless());
    }

    public function testMultiLineGridCellsDoNotImportNeighbouringCellText(): void
    {
        $rst = "+-----+-----+\n"
            . "| A   | B   |\n"
            . "+=====+=====+\n"
            . "| one | two |\n"
            . "| x   | y   |\n"
            . "+-----+-----+\n";

        $result = $this->convert($rst);

        self::assertSame("| A | B |\n| --- | --- |\n| one x | two y |\n", $result->output);
    }

    public function testCodeBlockOptionsAreDroppedWithAnIssue(): void
    {
        $result = $this->convert(".. code-block:: php\n   :linenos:\n\n   echo 1;\n");

        self::assertSame("```php\necho 1;\n```\n", $result->output);
        self::assertSame(['code-block:options' => 1], $result->report->countsByConstruct());
        self::assertSame(
            'Code block options dropped: linenos.',
            $result->report->ofKind(IssueKind::Lossy)[0]->message,
        );
    }

    public function testCustomAdmonitionTitlesBecomeABoldLine(): void
    {
        $result = $this->convert(".. admonition:: Custom Title\n\n   Body text.\n");

        self::assertSame("> [!NOTE]\n>\n> **Custom Title**\n>\n> Body text.\n", $result->output);
        self::assertSame(['directive:admonition' => 1], $result->report->countsByConstruct());
    }

    public function testImageDirectivesWithoutAUriBecomeAPlaceholder(): void
    {
        $result = $this->convert(".. image::\n");

        self::assertSame("<!-- rst: image -->\n", $result->output);
        self::assertSame(['directive:image' => 1], $result->report->countsByConstruct());
        self::assertFalse($result->report->isLossless());
    }

    public function testImageTargetsWrapTheImageInALinkAndDropLayoutOptions(): void
    {
        $rst = ".. image:: pic.png\n   :alt: A pic\n   :target: https://x.test\n   :width: 200\n";

        $result = $this->convert($rst);

        self::assertSame("[![A pic](pic.png)](https://x.test)\n", $result->output);
        self::assertSame(
            'Image options dropped: width.',
            $result->report->ofKind(IssueKind::Lossy)[0]->message,
        );
    }

    public function testCommentHyphenRunsCollapseToKeepTheHtmlCommentValid(): void
    {
        $result = $this->convert(".. a comment -- with dashes\n");

        self::assertSame("<!-- a comment - with dashes -->\n", $result->output);
        self::assertSame(['node:Comment' => 1], $result->report->countsByConstruct());
    }

    public function testFootnoteAndCitationReferencesStayPlainText(): void
    {
        $result = $this->convert("See [1]_ and [CIT2002]_.\n");

        self::assertSame("See \\[1\\] and \\[CIT2002\\].\n", $result->output);
        self::assertSame(
            ['inline:citation-reference' => 1, 'inline:footnote-reference' => 1],
            $result->report->countsByConstruct(),
        );
        self::assertCount(2, $result->report->ofKind(IssueKind::Lossy));
        self::assertTrue($result->isComplete());
        self::assertFalse($result->isLossless());
    }

    public function testResolvedFootnoteUsesNativeMarkdownFootnoteSyntax(): void
    {
        $result = $this->convert(
            "See [1]_.\n\n"
            . ".. [1] Footnote *body*.\n",
        );

        self::assertSame(
            "See [^fn-1].\n\n[^fn-1]: Footnote *body*.\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testResolvedFootnotesKeepEmptyAndMultiParagraphBodies(): void
    {
        $result = $this->convert(
            "See [1]_ and [2]_.\n\n"
            . ".. [1]\n\n"
            . ".. [2] First.\n\n"
            . "   Second.\n",
        );

        self::assertSame(
            "See [^fn-1] and [^fn-2].\n\n"
            . "[^fn-1]:\n\n"
            . "[^fn-2]: First.\n\n"
            . "    Second.\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testResolvedCitationUsesAnAnchoredMarkdownParagraph(): void
    {
        $result = $this->convert(
            "See [CIT2002]_.\n\n"
            . ".. [CIT2002] Citation **body**.\n",
        );

        self::assertSame(
            "See [CIT2002](#citation-cit2002).\n\n"
            . "<a id=\"citation-cit2002\"></a>\n\n"
            . "**[CIT2002]** Citation **body**.\n",
            $result->output,
        );
        self::assertSame(['citation' => 1], $result->report->countsByConstruct());
        self::assertTrue($result->report->isLossless());
    }

    public function testAnonymousReferencesWithoutATargetDegradeToReferenceLinks(): void
    {
        $result = $this->convert("See `anon`__ here.\n");

        self::assertSame("See [anon][anon] here.\n", $result->output);
        self::assertSame(['link:anonymous' => 1], $result->report->countsByConstruct());
    }

    public function testReferencesToStandaloneInternalTargetsLinkToTheirAnchor(): void
    {
        $result = $this->convert("See target_.\n\n.. _target:\n\nA paragraph.\n");

        self::assertSame(
            "See [target](#target).\n\n<a id=\"target\"></a>\n\nA paragraph.\n",
            $result->output,
        );
        self::assertSame(['target:internal' => 1], $result->report->countsByConstruct());
    }

    public function testEmbeddedUrisShareOneDefinitionInReferenceStyle(): void
    {
        $rst = "See `Docs <https://d.test>`_ and again `Docs <https://d.test>`_.\n";

        $result = $this->convert($rst, new ConversionOptions(linkStyle: LinkStyle::Reference));

        self::assertSame(
            "See [Docs][Docs] and again [Docs][Docs].\n\n[Docs]: https://d.test\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testConflictingEmbeddedUrisFallBackToAnInlineLink(): void
    {
        $rst = "See `Docs <https://d.test>`_ and `Docs <https://other.test>`_.\n";

        $result = $this->convert($rst, new ConversionOptions(linkStyle: LinkStyle::Reference));

        self::assertSame(
            "See [Docs][Docs] and [Docs](https://other.test).\n\n[Docs]: https://d.test\n",
            $result->output,
        );
    }

    public function testEmbeddedUrisConflictingWithANamedTargetStayInline(): void
    {
        $rst = "See `Docs <https://d.test>`_.\n\n.. _Docs: https://official.test\n";

        $result = $this->convert($rst, new ConversionOptions(linkStyle: LinkStyle::Reference));

        self::assertSame(
            "See [Docs](https://d.test).\n\n[Docs]: https://official.test\n",
            $result->output,
        );
    }

    public function testUrlsWithParenthesesAreAngleWrapped(): void
    {
        $result = $this->convert("Link `x <https://x.test/a(b)>`_.\n");

        self::assertSame("Link [x](<https://x.test/a(b)>).\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testLeadingBlockMarkupCharactersAreEscaped(): void
    {
        self::assertSame("\\# not a heading\n", $this->convert("\\# not a heading\n")->output);
        self::assertSame("\\---\n", $this->convert("\\---\n")->output);
    }

    public function testUnmappedNodesDegradeToAPlaceholderComment(): void
    {
        $span = ByteSpan::of(0, 1);
        $document = new Document($span, [new UnmappedNode($span)]);

        $result = new RstToMarkdown()->convert($document, Source::fromString(''), Profile::symfony());

        self::assertSame("<!-- rst: node UnmappedNode -->\n", $result->output);
        self::assertSame(['node:UnmappedNode' => 1], $result->report->countsByConstruct());
        self::assertFalse($result->report->isLossless());
    }

    public function testIndirectTargetsResolveThroughTheirReference(): void
    {
        $result = $this->convert("See alias_.\n\n.. _alias: real_\n.. _real: https://r.test\n");

        self::assertSame(
            "See [alias][alias].\n\n[alias]: https://r.test\n[real]: https://r.test\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testIndirectTargetsResolveThroughPhraseReferences(): void
    {
        $rst = "See alias_.\n\n.. _alias: `the real one`_\n.. _the real one: https://r.test\n";

        $result = $this->convert($rst);

        self::assertSame(
            "See [alias][alias].\n\n[alias]: https://r.test\n[the real one]: https://r.test\n",
            $result->output,
        );
    }

    public function testTargetAliasCyclesDegradeToAnchors(): void
    {
        $result = $this->convert("See a_.\n\n.. _a: b_\n.. _b: a_\n");

        self::assertSame(
            "See [a](#a).\n\n<a id=\"a\"></a>\n\n<a id=\"b\"></a>\n",
            $result->output,
        );
        self::assertSame(['target:internal' => 2], $result->report->countsByConstruct());
    }

    public function testDanglingIndirectTargetsDegradeToAnchors(): void
    {
        $result = $this->convert("See a_.\n\n.. _a: missing_\n");

        self::assertSame("See [a](#a).\n\n<a id=\"a\"></a>\n", $result->output);
        self::assertSame(['target:internal' => 1], $result->report->countsByConstruct());
    }

    public function testIndirectTargetsToSectionTitlesResolveToHeadingAnchors(): void
    {
        $rst = "Chapter One\n===========\n\nSee a_.\n\n.. _a: `Chapter One`_\n";

        $result = $this->convert($rst);

        self::assertSame(
            "# Chapter One\n\nSee [a][a].\n\n[a]: #chapter-one\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testRelativeUrlTargetsStayUrls(): void
    {
        $result = $this->convert("See a_.\n\n.. _a: page.html\n");

        self::assertSame("See [a][a].\n\n[a]: page.html\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testChainedTargetsAdoptTheNextTargetsUrl(): void
    {
        $result = $this->convert("See a_ and b_.\n\n.. _a:\n.. _b: https://b.test\n");

        self::assertSame(
            "See [a][a] and [b][b].\n\n[b]: https://b.test\n[a]: https://b.test\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testChainedTargetsAdoptAnAnonymousTargetsUrl(): void
    {
        $rst = "See name_ and here__.\n\n.. _name:\n.. __: https://anon.test\n";

        $result = $this->convert($rst);

        self::assertSame(
            "See [name][name] and [here](https://anon.test).\n\n[name]: https://anon.test\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testTrailingChainedTargetsBecomeAnchors(): void
    {
        $result = $this->convert("See a_.\n\n.. _a:\n.. _b:\n");

        self::assertSame(
            "See [a](#a).\n\n<a id=\"a\"></a>\n\n<a id=\"b\"></a>\n",
            $result->output,
        );
        self::assertSame(['target:internal' => 2], $result->report->countsByConstruct());
    }

    public function testChainedTargetsWithADanglingReferenceBecomeAnchors(): void
    {
        $result = $this->convert("See a_ and b_.\n\n.. _a:\n.. _b: missing_\n");

        self::assertSame(
            "See [a](#a) and [b](#b).\n\n<a id=\"a\"></a>\n\n<a id=\"b\"></a>\n",
            $result->output,
        );
        self::assertSame(['target:internal' => 2], $result->report->countsByConstruct());
    }

    public function testSubstitutionReferencesAndInlineTargetsStayPlainText(): void
    {
        $result = $this->convert("Uses |version| and an _`inline target` here.\n");

        self::assertSame("Uses |version| and an inline target here.\n", $result->output);
        self::assertSame(
            ['inline:substitution-reference' => 1, 'inline:target' => 1],
            $result->report->countsByConstruct(),
        );
        self::assertCount(2, $result->report->ofKind(IssueKind::Lossy));
        self::assertTrue($result->isComplete());
        self::assertFalse($result->isLossless());
    }

    public function testResolvedSubstitutionsExpandIntoMarkdown(): void
    {
        $result = $this->convert(
            ".. |project| replace:: **Alto**\n"
            . ".. |copy| unicode:: 0xA9\n"
            . ".. |logo| image:: logo.png\n"
            . "   :alt: Logo\n\n"
            . "Use |project|, |copy|, and |logo|.\n",
        );

        self::assertSame(
            "Use **Alto**, ©, and ![Logo](logo.png).\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testRowsShorterThanTheColumnCountArePaddedWithEmptyCells(): void
    {
        $span = ByteSpan::of(0, 10);
        $cell = static fn(string $text): TableCell => new TableCell($span, [new Paragraph($span, new Text($span, $text))]);
        $table = new Table(
            $span,
            [new TableRow($span, [$cell('h1'), $cell('h2')])],
            [new TableRow($span, [$cell('only')])],
            [5, 5],
            TableStyle::Simple,
        );
        $document = new Document($span, [$table]);

        $result = new RstToMarkdown()->convert($document, Source::fromString(''), Profile::symfony());

        self::assertSame("| h1 | h2 |\n| --- | --- |\n| only |  |\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testDefinitionListBecomesExplicitBulletItems(): void
    {
        $result = $this->convert("term *one* : kind\n  body **strong**\n\nnext\n  another body\n");

        self::assertSame(
            "- **term *one*** (kind)\n\n  body **strong**\n\n- **next**\n\n  another body\n",
            $result->output,
        );
        self::assertSame(['definition-list' => 1], $result->report->countsByConstruct());
        self::assertCount(1, $result->report->ofKind(IssueKind::Approximated));
    }

    public function testSymfonyConfigurationBlockUsesItsExtensionMapping(): void
    {
        $result = $this->convert(
            ".. configuration-block::\n\n"
            . "    .. code-block:: yaml\n\n"
            . "        key: value\n\n"
            . "    .. code-block:: php\n\n"
            . "        return [];\n",
        );

        self::assertSame("```yaml\nkey: value\n```\n\n```php\nreturn [];\n```\n", $result->output);
        self::assertSame(
            ['directive:configuration-block' => 1],
            $result->report->countsByConstruct(),
        );
        self::assertCount(1, $result->report->ofKind(IssueKind::Approximated));
    }

    public function testConfigurationBlockReanchorsNestedConversionIssues(): void
    {
        $rst = ".. configuration-block::\n\n"
            . "    .. code-block:: html+twig\n"
            . "       :linenos:\n\n"
            . "        <div />\n";
        $result = $this->convert($rst);

        self::assertSame(
            ['code-block:options' => 1, 'directive:configuration-block' => 1],
            $result->report->countsByConstruct(),
        );

        foreach ($result->report->issues as $issue) {
            self::assertNotNull($issue->span);
            self::assertSame(0, $issue->span->start);
            self::assertSame(\strlen($rst) - 1, $issue->span->end());
        }
    }

    public function testEmptyConfigurationBlockStillReportsItsApproximation(): void
    {
        $result = $this->convert(".. configuration-block::\n");

        self::assertSame('', $result->output);
        self::assertSame(
            ['directive:configuration-block' => 1],
            $result->report->countsByConstruct(),
        );
    }

    public function testConfigurationBlockUsesTheEnclosingDocumentsTargets(): void
    {
        $rst = ".. configuration-block::\n\n"
            . "    See target_.\n\n"
            . ".. _target: https://example.test\n";
        $source = Source::fromString($rst);
        $parsed = Rst::symfony()->parse($rst);
        $result = new RstToMarkdown()->convert(
            $parsed->document(),
            $source,
            Profile::symfony(),
            references: $parsed->references(),
        );

        self::assertSame(
            "See [target][target].\n\n[target]: https://example.test\n",
            $result->output,
        );
        self::assertSame(
            ['directive:configuration-block' => 1],
            $result->report->countsByConstruct(),
        );
    }

    public function testConfigurationBlockUsesProjectReferences(): void
    {
        $rst = ".. configuration-block::\n\n"
            . "    See :ref:`install-label` and :doc:`../install`.\n";
        $source = Source::fromString($rst);
        $parsed = Rst::symfony()->parse($rst);
        $install = Rst::symfony()->parse(".. _install-label:\n\nInstallation\n============\n");
        $map = new ProjectReferenceMap([
            'guide/start.rst' => $parsed->references(),
            'install.rst' => $install->references(),
        ]);
        $result = new RstToMarkdown()->convert(
            $parsed->document(),
            $source,
            Profile::symfony(),
            references: $parsed->references(),
            projectReferences: $map,
            sourcePath: 'guide/start.rst',
        );

        self::assertSame(
            "See [Installation](../install.md#install-label) and [Installation](../install.md).\n",
            $result->output,
        );
        self::assertSame(
            [
                'directive:configuration-block' => 1,
                'role:doc' => 1,
                'role:ref' => 1,
            ],
            $result->report->countsByConstruct(),
        );
    }

    public function testSidebarAndTopicBecomeQuotedAsides(): void
    {
        $sidebar = $this->convert(".. sidebar:: Useful context\n\n    Read *this*.\n");
        $topic = $this->convert(".. topic:: Example\n\n    Sample text.\n");

        self::assertSame(
            "> **Useful context**\n>\n> Read *this*.\n",
            $sidebar->output,
        );
        self::assertSame(
            "> **Example**\n>\n> Sample text.\n",
            $topic->output,
        );
        self::assertCount(1, $sidebar->report->ofKind(IssueKind::Approximated));
        self::assertCount(1, $topic->report->ofKind(IssueKind::Approximated));
    }

    public function testScreencastBecomesATip(): void
    {
        $result = $this->convert(".. screencast::\n\n    Watch the `series <https://example.test>`_.\n");

        self::assertSame(
            "> [!TIP]\n>\n> Watch the [series](https://example.test).\n",
            $result->output,
        );
        self::assertSame(['directive:screencast' => 1], $result->report->countsByConstruct());
        self::assertTrue($result->report->isLossless());
    }

    public function testClassDirectiveDropsOnlyTheUnavailableCssClass(): void
    {
        $result = $this->convert(".. class:: ui-list-two-columns\n\n* One\n* Two\n");

        self::assertSame("* One\n* Two\n", $result->output);
        self::assertSame(['directive:class' => 1], $result->report->countsByConstruct());
        self::assertCount(1, $result->report->ofKind(IssueKind::Lossy));
        self::assertTrue($result->report->isComplete());
        self::assertFalse($result->report->isLossless());
    }

    public function testNearbyDirectiveMappingsReportDroppedMetadata(): void
    {
        $note = $this->convert(".. note::\n    :class: highlighted\n\n    Body.\n");
        $sidebar = $this->convert(".. sidebar:: Title\n    :subtitle: Subtitle\n\n    Body.\n");
        $screencast = $this->convert(".. screencast:: series-name\n\n    Body.\n");
        $figure = $this->convert(".. figure:: image.png\n    :figclass: hero\n\n    Caption.\n");

        self::assertSame(['directive:note:options' => 1], $note->report->countsByConstruct());
        self::assertCount(1, $note->report->ofKind(IssueKind::Lossy));
        self::assertArrayHasKey('directive:sidebar:options', $sidebar->report->countsByConstruct());
        self::assertCount(1, $sidebar->report->ofKind(IssueKind::Lossy));
        self::assertArrayHasKey('directive:screencast:arguments', $screencast->report->countsByConstruct());
        self::assertCount(1, $screencast->report->ofKind(IssueKind::Lossy));
        self::assertArrayHasKey('directive:figure:options', $figure->report->countsByConstruct());
        self::assertCount(1, $figure->report->ofKind(IssueKind::Lossy));
    }

    public function testExplicitRawHtmlAuthorityStillRejectsUnsupportedRawShapes(): void
    {
        $options = new ConversionOptions(allowRawHtml: true);
        $wrongFormat = $this->convert(".. raw:: latex\n\n    text\n", $options);
        $withOptions = $this->convert(".. raw:: html\n    :file: fragment.html\n", $options);
        $withoutBody = $this->convert(".. raw:: html\n", $options);

        foreach ([$wrongFormat, $withOptions, $withoutBody] as $result) {
            self::assertSame("<!-- rst: directive raw -->\n", $result->output);
            self::assertCount(1, $result->report->ofKind(IssueKind::Unsupported));
        }
    }

    public function testDirectiveBodiesSeeTheEnclosingDocumentsTargets(): void
    {
        $rst = ".. note::\n\n   .. _inner: outer_\n\n   See inner_.\n\n.. _outer: https://o.test\n";

        $result = $this->convert($rst);

        self::assertSame(
            "> [!NOTE]\n>\n> See [inner][inner].\n\n[inner]: https://o.test\n[outer]: https://o.test\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testProjectMapResolvesCrossDocumentSphinxRoles(): void
    {
        $start = Source::fromString("See :ref:`install-label` and :doc:`/install`.\n");
        $startResult = Rst::sphinx()->parse($start->bytes);
        $installResult = Rst::sphinx()->parse(".. _install-label:\n\nInstallation\n============\n");
        $map = new ProjectReferenceMap([
            'guide/start.rst' => $startResult->references(),
            'install.rst' => $installResult->references(),
        ]);

        $result = new RstToMarkdown()->convert(
            $startResult->document(),
            $start,
            Profile::sphinx(),
            null,
            $startResult->references(),
            $map,
            'guide/start.rst',
        );

        self::assertSame(
            "See [Installation](../install.md#install-label) and [Installation](../install.md).\n",
            $result->output,
        );
    }
}
