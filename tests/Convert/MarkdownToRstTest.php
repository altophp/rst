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

use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\ConversionResult;
use Alto\Rst\Convert\IssueKind;
use Alto\Rst\Convert\LinkStyle;
use Alto\Rst\Convert\Markdown\MarkdownReader;
use Alto\Rst\Convert\Markdown\MdDocument;
use Alto\Rst\Convert\Markdown\MdHtmlBlock;
use Alto\Rst\Convert\MarkdownToRst;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Tests\Convert\Support\UnmappedMdNode;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\TestCase;

#[CoversNamespace('Alto\\Rst\\Convert')]
final class MarkdownToRstTest extends TestCase
{
    private function convert(string $markdown, ?ConversionOptions $options = null): ConversionResult
    {
        $document = new MarkdownReader()->read($markdown);

        return new MarkdownToRst()->convert($document, $options);
    }

    public function testAnEmptyDocumentConvertsToAnEmptyString(): void
    {
        $result = $this->convert('');

        self::assertSame('', $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testHeadingsBecomeAdornedTitlesInLevelOrder(): void
    {
        $md = <<<'MD'
            # Title

            Intro.

            ## Sub

            ### Deep
            MD;

        $result = $this->convert($md);

        self::assertSame(
            "Title\n=====\n\nIntro.\n\nSub\n---\n\nDeep\n~~~~\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testInlineMarkupMapsToRst(): void
    {
        $result = $this->convert('Use *this* and **that** with `code()`.');

        self::assertSame("Use *this* and **that** with ``code()``.\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testFencedCodeWithLanguageBecomesACodeBlockDirective(): void
    {
        $md = <<<'MD'
            ```yaml
            framework:
                secret: true
            ```
            MD;

        $result = $this->convert($md);

        self::assertSame(
            ".. code-block:: yaml\n\n    framework:\n        secret: true\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testTwigFencesStayTwigByDefault(): void
    {
        $result = $this->convert("```twig\n{{ value }}\n```\n");

        self::assertSame(".. code-block:: twig\n\n    {{ value }}\n", $result->output);
    }

    public function testFencedCodeWithoutInfoBecomesALiteralBlock(): void
    {
        $result = $this->convert("```\nplain text\n```\n");

        self::assertSame("::\n\n    plain text\n", $result->output);
    }

    public function testGithubAlertsBecomeAdmonitions(): void
    {
        $md = <<<'MD'
            > [!NOTE]
            >
            > Take *note* of this.
            MD;

        $result = $this->convert($md);

        self::assertSame(".. note::\n\n    Take *note* of this.\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testAlertMarkerAndBodyOnTheSameLineStillConverts(): void
    {
        $result = $this->convert("> [!WARNING] Careful now.\n");

        self::assertSame(".. warning::\n\n    Careful now.\n", $result->output);
    }

    public function testBoldVersionLinesBecomeVersionDirectives(): void
    {
        $md = <<<'MD'
            > **New in version 2.20**
            >
            > The ability to do this was added.
            MD;

        $result = $this->convert($md);

        self::assertSame(
            ".. versionadded:: 2.20\n\n    The ability to do this was added.\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testBoldAdmonitionLabelsBecomeAdmonitions(): void
    {
        $md = <<<'MD'
            > **Warning**
            >
            > Careful.
            MD;

        $result = $this->convert($md);

        self::assertSame(".. warning::\n\n    Careful.\n", $result->output);
    }

    public function testPlainBlockQuotesBecomeIndentedBlocks(): void
    {
        $result = $this->convert("paragraph\n\n> quoted text\n");

        self::assertSame("paragraph\n\n    quoted text\n", $result->output);
    }

    public function testReferenceLinksBecomeRstTargets(): void
    {
        $md = <<<'MD'
            See the [Symfony][Symfony] docs.

            [Symfony]: https://symfony.com
            MD;

        $result = $this->convert($md);

        self::assertSame(
            "See the Symfony_ docs.\n\n.. _Symfony: https://symfony.com\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testPhraseReferenceLinksUseTheBackquotedForm(): void
    {
        $md = <<<'MD'
            Read [the manual][the manual] first.

            [the manual]: https://example.com/manual
            MD;

        $result = $this->convert($md);

        self::assertSame(
            "Read `the manual`_ first.\n\n.. _`the manual`: https://example.com/manual\n",
            $result->output,
        );
    }

    public function testInlineLinksBecomeEmbeddedUriLinks(): void
    {
        $result = $this->convert('See [the docs](https://example.com) now.');

        self::assertSame("See `the docs <https://example.com>`_ now.\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testAutolinksBecomeStandaloneUris(): void
    {
        $result = $this->convert('Browse <https://example.com/a> today.');

        self::assertSame("Browse https://example.com/a today.\n", $result->output);
    }

    public function testPipeTablesBecomeSimpleTables(): void
    {
        $md = <<<'MD'
            | Name | Meaning |
            | --- | --- |
            | a | first |
            | b | second |
            MD;

        $result = $this->convert($md);

        self::assertSame(
            "====  =======\nName  Meaning\n====  =======\na     first\nb     second\n====  =======\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testTableAlignmentIsReportedAsLossy(): void
    {
        $md = <<<'MD'
            | Name | Meaning |
            | :--- | ---: |
            | a | first |
            MD;

        $result = $this->convert($md);

        self::assertSame(['md:table-alignment' => 1], $result->report->countsByConstruct());
        self::assertCount(1, $result->report->ofKind(IssueKind::Lossy));
    }

    public function testListsKeepMarkersNumbersAndTightness(): void
    {
        $md = <<<'MD'
            * one
            * two

            3. three
            4. four
            MD;

        $result = $this->convert($md);

        self::assertSame("* one\n* two\n\n3. three\n4. four\n", $result->output);
    }

    public function testLooseListItemsKeepTheirParagraphs(): void
    {
        $md = <<<'MD'
            - first

              more about first

            - second
            MD;

        $result = $this->convert($md);

        self::assertSame("- first\n\n  more about first\n\n- second\n", $result->output);
    }

    public function testThematicBreaksBecomeTransitions(): void
    {
        $result = $this->convert("before\n\n---\n\nafter\n");

        self::assertSame("before\n\n----\n\nafter\n", $result->output);
    }

    public function testHtmlCommentsBecomeRstComments(): void
    {
        $result = $this->convert("<!-- a private note -->\n");

        self::assertSame(".. a private note\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testRawHtmlBlocksProduceAnIssueAndACommentPlaceholder(): void
    {
        $result = $this->convert("<div class=\"x\">boxed</div>\n");

        self::assertSame(
            "..\n   raw HTML block:\n\n   <div class=\"x\">boxed</div>\n",
            $result->output,
        );
        self::assertFalse($result->report->isLossless());
        self::assertSame(['md:html-block' => 1], $result->report->countsByConstruct());
    }

    public function testImagesBecomeImageDirectives(): void
    {
        $result = $this->convert("![A map](pictures/map.png)\n");

        self::assertSame(".. image:: pictures/map.png\n    :alt: A map\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testBlockImageReportsDroppedTitleMetadata(): void
    {
        $result = $this->convert("![A map](pictures/map.png \"Map title\")\n");

        self::assertSame(".. image:: pictures/map.png\n    :alt: A map\n", $result->output);
        self::assertSame(['md:image-metadata' => 1], $result->report->countsByConstruct());
        self::assertCount(1, $result->report->ofKind(IssueKind::Lossy));
        self::assertTrue($result->isComplete());
        self::assertFalse($result->isLossless());
    }

    public function testBlockImageReportsDroppedReferenceStyle(): void
    {
        $result = $this->convert("![A map][map]\n\n[map]: pictures/map.png\n");

        self::assertSame(
            ".. image:: pictures/map.png\n"
            . "    :alt: A map\n\n"
            . ".. _map: pictures/map.png\n",
            $result->output,
        );
        self::assertSame(['md:image-metadata' => 1], $result->report->countsByConstruct());
        self::assertFalse($result->isLossless());
    }

    public function testInlineImagesKeepTheirAltTextWithAnIssue(): void
    {
        $result = $this->convert("Look at ![the map](map.png) here.\n");

        self::assertSame("Look at the map here.\n", $result->output);
        self::assertSame(['md:inline-image' => 1], $result->report->countsByConstruct());
        self::assertCount(1, $result->report->ofKind(IssueKind::Lossy));
        self::assertTrue($result->isComplete());
        self::assertFalse($result->isLossless());
    }

    public function testNestedInlineMarkupFlattensWithAnIssue(): void
    {
        $result = $this->convert("This is **bold with *nested* words**.\n");

        self::assertSame("This is **bold with nested words**.\n", $result->output);
        self::assertSame(['md:nested-markup' => 1], $result->report->countsByConstruct());
    }

    public function testMarkupCharactersInPlainTextAreEscaped(): void
    {
        $result = $this->convert("Star \\* pipe \\| trail\\_ but snake_case stays.\n");

        self::assertSame("Star \\* pipe \\| trail\\_ but snake_case stays.\n", $result->output);
    }

    public function testCustomSectionAdornmentsAreHonored(): void
    {
        $result = $this->convert("# One\n\n## Two\n", ConversionOptions::symfony());

        self::assertSame("One\n===\n\nTwo\n---\n", $result->output);
    }

    public function testOutputAlwaysEndsWithASingleNewline(): void
    {
        $result = $this->convert("word\n\n\n");

        self::assertSame("word\n", $result->output);
    }

    public function testDuplicateLinkDefinitionsCollapseToTheFirst(): void
    {
        $result = $this->convert("[a]\n\n[a]: https://one.test\n[A]: https://two.test\n");

        self::assertSame("a_\n\n.. _a: https://one.test\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testLinkDefinitionTitlesAreDroppedWithAnIssue(): void
    {
        $result = $this->convert("[a]\n\n[a]: https://one.test \"A Title\"\n");

        self::assertSame("a_\n\n.. _a: https://one.test\n", $result->output);
        self::assertSame(['md:link-title' => 2], $result->report->countsByConstruct());
        self::assertCount(2, $result->report->ofKind(IssueKind::Lossy));
    }

    public function testForcedReferenceStyleTurnsInlineLinksIntoTargets(): void
    {
        $md = "See [Docs](https://d.test) twice: [Docs](https://d.test).\n";

        $result = $this->convert($md, new ConversionOptions(linkStyle: LinkStyle::Reference));

        self::assertSame(
            "See Docs_ twice: Docs_.\n\n.. _Docs: https://d.test\n",
            $result->output,
        );
        self::assertTrue($result->report->isEmpty());
    }

    public function testForcedReferenceStyleInlinesConflictingLinkTexts(): void
    {
        $md = "See [Docs](https://d.test) and [Docs](https://other.test).\n";

        $result = $this->convert($md, new ConversionOptions(linkStyle: LinkStyle::Reference));

        self::assertSame(
            "See Docs_ and `Docs <https://other.test>`_.\n\n.. _Docs: https://d.test\n",
            $result->output,
        );
    }

    public function testForcedReferenceTargetsYieldToAnExistingDefinitionLabel(): void
    {
        $md = "See [x](https://inline.test).\n\n[x]: https://def.test\n";

        $result = $this->convert($md, new ConversionOptions(linkStyle: LinkStyle::Reference));

        self::assertSame(
            "See `x <https://inline.test>`_.\n\n.. _x: https://def.test\n",
            $result->output,
        );
    }

    public function testForcedReferenceTargetsReuseAnExistingDefinitionWithTheSameUrl(): void
    {
        $md = "See [x](https://same.test).\n\n[x]: https://same.test\n";

        $result = $this->convert($md, new ConversionOptions(linkStyle: LinkStyle::Reference));

        self::assertSame("See x_.\n\n.. _x: https://same.test\n", $result->output);
    }

    public function testAnEmptyFenceWithoutInfoProducesNothing(): void
    {
        $result = $this->convert("```\n```\n");

        self::assertSame('', $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testAnEmptyFenceWithALanguageKeepsTheBareDirective(): void
    {
        $result = $this->convert("```php\n```\n");

        self::assertSame(".. code-block:: php\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testFenceInfoStringsReduceToTheLanguageWithAnIssue(): void
    {
        $result = $this->convert("```php title=\"x\"\necho 1;\n```\n");

        self::assertSame(".. code-block:: php\n\n    echo 1;\n", $result->output);
        self::assertSame(['md:info-string' => 1], $result->report->countsByConstruct());
        self::assertSame(
            'Fence info "php title="x"" reduced to its language, "php".',
            $result->report->ofKind(IssueKind::Lossy)[0]->message,
        );
    }

    public function testTextOnTheVersionLabelLineBecomesTheDirectiveBody(): void
    {
        $result = $this->convert("> **New in version 7.2** The thing.\n");

        self::assertSame(".. versionadded:: 7.2\n\n    The thing.\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testABareBoldLabelBecomesAnEmptyAdmonition(): void
    {
        $result = $this->convert("> **Note**\n");

        self::assertSame(".. note::\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testALabelFollowedOnlyByADroppedCommentKeepsTheAdmonitionEmpty(): void
    {
        $result = $this->convert("> **Note** <!-- todo -->\n");

        self::assertSame(".. note::\n", $result->output);
        self::assertSame(['md:inline-html' => 1], $result->report->countsByConstruct());
        self::assertCount(1, $result->report->ofKind(IssueKind::Lossy));
        self::assertTrue($result->isComplete());
        self::assertFalse($result->isLossless());
    }

    public function testAnEmptyListItemWritesItsBareMarker(): void
    {
        $result = $this->convert("- \n- two\n");

        self::assertSame("-\n\n- two\n", $result->output);
    }

    public function testATightListWithANestedListSeparatesItsItems(): void
    {
        $result = $this->convert("- one\n  - nested\n- two\n");

        self::assertSame("- one\n\n  - nested\n\n- two\n", $result->output);
    }

    public function testTablesWithoutBodyRowsProduceAnIssue(): void
    {
        $result = $this->convert("| h1 | h2 |\n| --- | --- |\n");

        self::assertSame("==  ==\nh1  h2\n==  ==\n", $result->output);
        self::assertSame(['md:table-headless' => 1], $result->report->countsByConstruct());
    }

    public function testAnEmptyFirstTableCellProducesAnIssue(): void
    {
        $result = $this->convert("| h1 | h2 |\n| --- | --- |\n|  | x |\n");

        self::assertSame("==  ==\nh1  h2\n==  ==\n    x\n==  ==\n", $result->output);
        self::assertSame(['md:table-empty-cell' => 1], $result->report->countsByConstruct());
    }

    public function testAnchorHtmlBlocksBecomeInternalTargets(): void
    {
        $span = ByteSpan::of(0, 24);
        $document = new MdDocument($span, [new MdHtmlBlock($span, '<a id="my-target"></a>')]);

        $result = new MarkdownToRst()->convert($document);

        self::assertSame(".. _my-target:\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testAnEmptyHtmlCommentBecomesAnEmptyRstComment(): void
    {
        $result = $this->convert("<!-- -->\n");

        self::assertSame("..\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testMultiLineHtmlCommentsBecomeIndentedRstComments(): void
    {
        $result = $this->convert("<!-- line one\nline two -->\n");

        self::assertSame("..\n   line one\n   line two\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testUnmappedMarkdownNodesDegradeToACommentPlaceholder(): void
    {
        $span = ByteSpan::of(0, 1);
        $document = new MdDocument($span, [new UnmappedMdNode($span)]);

        $result = new MarkdownToRst()->convert($document);

        self::assertSame(".. UnmappedMdNode dropped by markdown-to-rst\n", $result->output);
        self::assertSame(['md:unmappedmdnode' => 1], $result->report->countsByConstruct());
        self::assertFalse($result->report->isLossless());
    }

    public function testCodeSpansWithEdgeBackticksArePadded(): void
    {
        $result = $this->convert("Use `` `tick `` here.\n");

        self::assertSame("Use `` `tick `` here.\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testCodeSpansWithDoubleBackticksAreSplitWithAnIssue(): void
    {
        $result = $this->convert("Use ``` a``b ``` here.\n");

        self::assertSame("Use ``a` `b`` here.\n", $result->output);
        self::assertSame(['md:code-span' => 1], $result->report->countsByConstruct());
    }

    public function testLinkTitlesAreDroppedWithAnIssue(): void
    {
        $result = $this->convert("See [x](https://x.test \"A Title\").\n");

        self::assertSame("See `x <https://x.test>`_.\n", $result->output);
        self::assertSame(['md:link-title' => 1], $result->report->countsByConstruct());
    }

    public function testAutolinksStayBareUrls(): void
    {
        $result = $this->convert("Go to <https://x.test> now.\n");

        self::assertSame("Go to https://x.test now.\n", $result->output);
        self::assertTrue($result->report->isEmpty());
    }

    public function testFullReferenceLabelsAreReKeyedToTheLinkText(): void
    {
        $result = $this->convert("See [the docs][docs].\n\n[docs]: https://d.test\n");

        self::assertSame(
            "See `the docs`_.\n\n.. _docs: https://d.test\n.. _`the docs`: https://d.test\n",
            $result->output,
        );
        self::assertSame(['md:reference-label' => 1], $result->report->countsByConstruct());
        self::assertSame(
            'Reference label "docs" re-keyed to the link text "the docs".',
            $result->report->ofKind(IssueKind::Approximated)[0]->message,
        );
    }

    public function testConflictingReKeyedReferenceLabelsFallBackToAnInlineLink(): void
    {
        $md = "See [x][docs] and [x][other].\n\n[docs]: https://d.test\n[other]: https://o.test\n";

        $result = $this->convert($md);

        self::assertSame(
            "See x_ and `x <https://o.test>`_.\n\n.. _docs: https://d.test\n.. _other: https://o.test\n.. _x: https://d.test\n",
            $result->output,
        );
        self::assertCount(1, $result->report->ofKind(IssueKind::Approximated));
        self::assertSame(
            'Reference label "other" conflicts with an existing target; the link was inlined.',
            $result->report->ofKind(IssueKind::Lossy)[0]->message,
        );
    }

    public function testMarkupInsideLinkTextFlattensWithAnIssue(): void
    {
        $result = $this->convert("See [**bold** docs](https://d.test).\n");

        self::assertSame("See `bold docs <https://d.test>`_.\n", $result->output);
        self::assertSame(['md:nested-markup' => 1], $result->report->countsByConstruct());
    }

    public function testLineBreakTagsCollapseToASpace(): void
    {
        $result = $this->convert("line one<br>line two\n");

        self::assertSame("line one line two\n", $result->output);
        self::assertSame(['md:inline-html' => 1], $result->report->countsByConstruct());
        self::assertCount(1, $result->report->ofKind(IssueKind::Lossy));
    }

    public function testInlineHtmlCommentsAreDropped(): void
    {
        $result = $this->convert("before <!-- gone --> after\n");

        self::assertSame("before  after\n", $result->output);
        self::assertCount(1, $result->report->ofKind(IssueKind::Lossy));
        self::assertTrue($result->isComplete());
        self::assertFalse($result->isLossless());
    }

    public function testLeadingRstBlockMarkupIsEscaped(): void
    {
        self::assertSame("1\\. not a list\n", $this->convert("1\\. not a list\n")->output);
        self::assertSame("\\- not a list\n", $this->convert("\\- not a list\n")->output);
        self::assertSame("\\.. note:: not a directive\n", $this->convert("\\.. note:: not a directive\n")->output);
        self::assertSame("\\====\n", $this->convert("====\n")->output);
        self::assertSame("\\__\n", $this->convert("[__](__)\n")->output);
        self::assertSame("\\:field: not a field\n", $this->convert(":field: not a field\n")->output);
    }
}
