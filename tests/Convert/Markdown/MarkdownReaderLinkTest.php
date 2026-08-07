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

namespace Alto\Rst\Tests\Convert\Markdown;

use Alto\Rst\Convert\Markdown\BlockDraft;
use Alto\Rst\Convert\Markdown\DocumentDraft;
use Alto\Rst\Convert\Markdown\MarkdownBlockParser;
use Alto\Rst\Convert\Markdown\MarkdownInlineItem;
use Alto\Rst\Convert\Markdown\MarkdownInlineParser;
use Alto\Rst\Convert\Markdown\MarkdownReader;
use Alto\Rst\Convert\Markdown\MdEmphasis;
use Alto\Rst\Convert\Markdown\MdImage;
use Alto\Rst\Convert\Markdown\MdInlineHtml;
use Alto\Rst\Convert\Markdown\MdLineCursor;
use Alto\Rst\Convert\Markdown\MdLink;
use Alto\Rst\Convert\Markdown\MdLinkReferenceDefinition;
use Alto\Rst\Convert\Markdown\MdLinkStyle;
use Alto\Rst\Convert\Markdown\MdParagraph;
use Alto\Rst\Convert\Markdown\MdText;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MarkdownReader::class)]
#[CoversClass(BlockDraft::class)]
#[CoversClass(DocumentDraft::class)]
#[CoversClass(MarkdownBlockParser::class)]
#[CoversClass(MarkdownInlineItem::class)]
#[CoversClass(MarkdownInlineParser::class)]
#[CoversClass(MdLineCursor::class)]
#[CoversClass(MdLink::class)]
#[CoversClass(MdImage::class)]
#[CoversClass(MdLinkStyle::class)]
#[CoversClass(MdLinkReferenceDefinition::class)]
#[CoversClass(MdInlineHtml::class)]
final class MarkdownReaderLinkTest extends MarkdownReaderTestCase
{
    private static function firstInline(string $markdown): MdLink
    {
        $paragraph = self::read($markdown)->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $link = $paragraph->children()[0];
        self::assertInstanceOf(MdLink::class, $link);

        return $link;
    }

    public function testInlineLinkWithTitle(): void
    {
        $paragraph = self::read("[text](https://example.com \"Title\")\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $link = $paragraph->children()[0];
        self::assertInstanceOf(MdLink::class, $link);
        self::assertSame('https://example.com', $link->url);
        self::assertSame('Title', $link->title);
        self::assertSame(MdLinkStyle::Inline, $link->style);
        $text = $link->children()[0];
        self::assertInstanceOf(MdText::class, $text);
        self::assertSame('text', $text->text);
    }

    public function testInlineLinkWithoutTitle(): void
    {
        $paragraph = self::read("[text](https://example.com)\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $link = $paragraph->children()[0];
        self::assertInstanceOf(MdLink::class, $link);
        self::assertSame('https://example.com', $link->url);
        self::assertNull($link->title);
    }

    public function testInlineLinkWithAngleBracketDestination(): void
    {
        $paragraph = self::read("[text](<https://example.com/a b>)\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $link = $paragraph->children()[0];
        self::assertInstanceOf(MdLink::class, $link);
        self::assertSame('https://example.com/a b', $link->url);
    }

    public function testLinkTextIsParsedAsInline(): void
    {
        $paragraph = self::read("[*bold* text](https://example.com)\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $link = $paragraph->children()[0];
        self::assertInstanceOf(MdLink::class, $link);
        self::assertInstanceOf(MdEmphasis::class, $link->children()[0]);
    }

    public function testFullReferenceLink(): void
    {
        $markdown = "[text][label]\n\n[label]: https://example.com \"Title\"\n";
        $document = self::read($markdown);
        $paragraph = $document->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $link = $paragraph->children()[0];
        self::assertInstanceOf(MdLink::class, $link);
        self::assertSame('https://example.com', $link->url);
        self::assertSame('Title', $link->title);
        self::assertSame(MdLinkStyle::Reference, $link->style);
        self::assertSame('label', $link->referenceLabel);
    }

    public function testForwardReferenceResolvesAgainstADefinitionDeclaredLater(): void
    {
        $markdown = "[text][label]\n\n[label]: https://example.com\n";
        $link = self::firstInline($markdown);
        self::assertInstanceOf(MdLink::class, $link);
        self::assertSame('https://example.com', $link->url);
    }

    public function testCollapsedReferenceLink(): void
    {
        $markdown = "[text][]\n\n[text]: https://example.com\n";
        $link = self::firstInline($markdown);
        self::assertInstanceOf(MdLink::class, $link);
        self::assertSame('https://example.com', $link->url);
        self::assertSame('text', $link->referenceLabel);
    }

    public function testShortcutReferenceLink(): void
    {
        $markdown = "[text]\n\n[text]: https://example.com\n";
        $link = self::firstInline($markdown);
        self::assertInstanceOf(MdLink::class, $link);
        self::assertSame('https://example.com', $link->url);
    }

    public function testReferenceLabelMatchingIsCaseInsensitive(): void
    {
        $markdown = "[text][LABEL]\n\n[label]: https://example.com\n";
        $link = self::firstInline($markdown);
        self::assertInstanceOf(MdLink::class, $link);
        self::assertSame('https://example.com', $link->url);
    }

    public function testUndefinedReferenceDegradesToLiteralText(): void
    {
        $paragraph = self::read("[text][nope]\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $inline = $paragraph->children();
        self::assertCount(1, $inline);
        self::assertInstanceOf(MdText::class, $inline[0]);
        self::assertSame('[text][nope]', $inline[0]->text);
    }

    public function testLinkReferenceDefinitionDoesNotRenderAsAParagraph(): void
    {
        $document = self::read("[label]: https://example.com\n");
        self::assertSame([], $document->children());
    }

    public function testLinkReferenceDefinitionIsRecordedOnTheDocument(): void
    {
        $document = self::read("[label]: https://example.com \"Title\"\n");
        self::assertCount(1, $document->linkReferenceDefinitions);
        $definition = $document->linkReferenceDefinitions[0];
        self::assertSame('label', $definition->label);
        self::assertSame('label', $definition->normalizedLabel);
        self::assertSame('https://example.com', $definition->url);
        self::assertSame('Title', $definition->title);
    }

    public function testDefinitionFollowedByAParagraphOnTheNextLine(): void
    {
        $document = self::read("[label]: https://example.com\nParagraph.\n");
        self::assertCount(1, $document->linkReferenceDefinitions);
        self::assertCount(1, $document->children());
        self::assertInstanceOf(MdParagraph::class, $document->children()[0]);
    }

    public function testImageWithTitle(): void
    {
        $paragraph = self::read("![alt text](https://example.com/x.png \"Title\")\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $image = $paragraph->children()[0];
        self::assertInstanceOf(MdImage::class, $image);
        self::assertSame('https://example.com/x.png', $image->url);
        self::assertSame('Title', $image->title);
        $alt = $image->children()[0];
        self::assertInstanceOf(MdText::class, $alt);
        self::assertSame('alt text', $alt->text);
    }

    public function testUriAutolink(): void
    {
        $paragraph = self::read("<https://example.com>\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $link = $paragraph->children()[0];
        self::assertInstanceOf(MdLink::class, $link);
        self::assertSame('https://example.com', $link->url);
        $text = $link->children()[0];
        self::assertInstanceOf(MdText::class, $text);
        self::assertSame('https://example.com', $text->text);
    }

    public function testEmailAutolink(): void
    {
        $paragraph = self::read("<user@example.com>\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $link = $paragraph->children()[0];
        self::assertInstanceOf(MdLink::class, $link);
        self::assertSame('mailto:user@example.com', $link->url);
    }

    public function testInlineHtmlTagsAroundText(): void
    {
        $paragraph = self::read("<span class=\"x\">text</span>\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $inline = $paragraph->children();
        self::assertCount(3, $inline);
        self::assertInstanceOf(MdInlineHtml::class, $inline[0]);
        self::assertSame('<span class="x">', $inline[0]->content);
        self::assertInstanceOf(MdText::class, $inline[1]);
        self::assertSame('text', $inline[1]->text);
        self::assertInstanceOf(MdInlineHtml::class, $inline[2]);
        self::assertSame('</span>', $inline[2]->content);
    }

    public function testLinkSpanCoversTheWholeConstruct(): void
    {
        $paragraph = self::read("[text](url)\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $link = $paragraph->children()[0];
        self::assertInstanceOf(MdLink::class, $link);
        self::assertSpan(0, \strlen('[text](url)'), $link);
    }

    public function testFullReferenceImage(): void
    {
        $markdown = "![alt][label]\n\n[label]: https://example.com/x.png\n";
        $paragraph = self::read($markdown)->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $image = $paragraph->children()[0];
        self::assertInstanceOf(MdImage::class, $image);
        self::assertSame('https://example.com/x.png', $image->url);
        self::assertSame(MdLinkStyle::Reference, $image->style);
    }

    public function testCollapsedReferenceImage(): void
    {
        $markdown = "![alt][]\n\n[alt]: https://example.com/x.png\n";
        $paragraph = self::read($markdown)->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $image = $paragraph->children()[0];
        self::assertInstanceOf(MdImage::class, $image);
        self::assertSame('https://example.com/x.png', $image->url);
    }

    public function testEscapedClosingBracketInLinkTextIsNotTheClosingBracket(): void
    {
        $link = self::firstInline("[a\\]b](url)\n");
        self::assertSame('url', $link->url);
        $text = $link->children()[0];
        self::assertInstanceOf(MdText::class, $text);
        self::assertSame('a]b', $text->text);
    }

    public function testCodeSpanInsideLinkTextHidesItsBracketsFromTheSearch(): void
    {
        $link = self::firstInline("[a `]` b](url)\n");
        self::assertSame('url', $link->url);
        self::assertCount(3, $link->children());
    }

    public function testBalancedParenthesesInABareUrlDestination(): void
    {
        $link = self::firstInline('[text](url(nested))'."\n");
        self::assertSame('url(nested)', $link->url);
    }

    public function testEscapedQuoteInsideATitle(): void
    {
        $link = self::firstInline("[text](url \"a \\\"quoted\\\" title\")\n");
        self::assertSame('a "quoted" title', $link->title);
    }

    public function testUnterminatedTitleFallsBackToLiteralBracket(): void
    {
        $paragraph = self::read("[text](url \"unterminated)\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $text = $paragraph->children()[0];
        self::assertInstanceOf(MdText::class, $text);
        self::assertStringStartsWith('[text]', $text->text);
    }

    public function testDefinitionUrlOnTheLineAfterTheLabel(): void
    {
        $document = self::read("[label]:\nhttps://example.com\n");
        self::assertCount(1, $document->linkReferenceDefinitions);
        self::assertSame('https://example.com', $document->linkReferenceDefinitions[0]->url);
    }

    public function testDefinitionTitleOnItsOwnContinuationLine(): void
    {
        $document = self::read("[label]: https://example.com\n\"Title\"\n");
        self::assertCount(1, $document->linkReferenceDefinitions);
        self::assertSame('Title', $document->linkReferenceDefinitions[0]->title);
    }

    public function testDefinitionWithAParenthesizedTitle(): void
    {
        $document = self::read("[label]: https://example.com (Title)\n");
        self::assertCount(1, $document->linkReferenceDefinitions);
        self::assertSame('Title', $document->linkReferenceDefinitions[0]->title);
    }

    public function testDefinitionWithAnEscapedLabelCharacter(): void
    {
        $document = self::read("[a\\]b]: https://example.com\n");
        self::assertCount(1, $document->linkReferenceDefinitions);
        self::assertSame('a]b', $document->linkReferenceDefinitions[0]->label);
    }

    public function testDefinitionWithAnUnterminatedAngleBracketUrlIsNotADefinition(): void
    {
        $document = self::read("[label]: <unterminated\n");
        self::assertSame([], $document->linkReferenceDefinitions);
        self::assertCount(1, $document->children());
        self::assertInstanceOf(MdParagraph::class, $document->children()[0]);
    }

    public function testDefinitionWithAnUnterminatedTitleIsNotADefinition(): void
    {
        $document = self::read("[label]: https://example.com \"unterminated\n");
        self::assertSame([], $document->linkReferenceDefinitions);
        self::assertCount(1, $document->children());
        self::assertInstanceOf(MdParagraph::class, $document->children()[0]);
    }

    public function testDefinitionWithASingleQuotedTitle(): void
    {
        $document = self::read("[label]: https://example.com 'Title'\n");
        self::assertCount(1, $document->linkReferenceDefinitions);
        self::assertSame('Title', $document->linkReferenceDefinitions[0]->title);
    }

    public function testDefinitionWithATooShortTitleCandidateIsNotADefinition(): void
    {
        $document = self::read("[label]: https://example.com \"\n");
        self::assertSame([], $document->linkReferenceDefinitions);
        self::assertCount(1, $document->children());
        self::assertInstanceOf(MdParagraph::class, $document->children()[0]);
    }

    public function testNestedBracketsInLinkTextAreKept(): void
    {
        $link = self::firstInline("[a [b] c](url)\n");
        self::assertSame('url', $link->url);
        $text = $link->children()[0];
        self::assertInstanceOf(MdText::class, $text);
        self::assertSame('a [b] c', $text->text);
    }

    public function testMismatchedBacktickRunsInLinkTextAreSkippedCorrectly(): void
    {
        $link = self::firstInline("[`x``y` link](url)\n");
        self::assertSame('url', $link->url);
    }

    public function testUnterminatedAngleBracketDestinationFallsBackToLiteralBracket(): void
    {
        $paragraph = self::read("[text](<unterminated)\n")->children()[0];
        self::assertInstanceOf(MdParagraph::class, $paragraph);
        $text = $paragraph->children()[0];
        self::assertInstanceOf(MdText::class, $text);
        self::assertStringStartsWith('[text]', $text->text);
    }
}
