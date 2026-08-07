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

namespace Alto\Rst\Tests\Render;

use Alto\Rst\Node\BlockQuote;
use Alto\Rst\Node\BulletList;
use Alto\Rst\Node\Comment;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\EnumeratedList;
use Alto\Rst\Node\EnumerationStyle;
use Alto\Rst\Node\FootnoteDefinition;
use Alto\Rst\Node\HyperlinkTarget;
use Alto\Rst\Node\ListItem;
use Alto\Rst\Node\LiteralBlock;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Section;
use Alto\Rst\Node\Text;
use Alto\Rst\Node\Title;
use Alto\Rst\Node\Transition;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Render\HtmlPolicy;
use Alto\Rst\Render\HtmlRenderer;
use Alto\Rst\Render\RenderOptions;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlRenderer::class)]
final class HtmlRendererTest extends TestCase
{
    public function testEmptyDocument(): void
    {
        $source = Source::fromString('');
        $document = new Document(ByteSpan::of(0, 0));

        self::assertSame('', (new HtmlRenderer())->render($document, $source));
    }

    public function testParagraph(): void
    {
        $rst = "Hello world\n";
        $source = Source::fromString($rst);
        $paragraph = new Paragraph(self::span($rst, 'Hello world'), self::text($rst, 'Hello world'));
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$paragraph]);

        self::assertSame("<p>Hello world</p>\n", (new HtmlRenderer())->render($document, $source));
    }

    public function testSectionWithSlugifiedId(): void
    {
        $rst = "Hello, World!\n=============\n\nBody text\n";
        $source = Source::fromString($rst);
        $title = new Title(self::span($rst, 'Hello, World!'), self::text($rst, 'Hello, World!'));
        $paragraph = new Paragraph(self::span($rst, 'Body text'), self::text($rst, 'Body text'));
        $section = new Section(ByteSpan::of(0, \strlen($rst)), 1, $title, [$paragraph], '=', false);
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$section]);

        $expected = "<section id=\"hello-world\">\n<h1>Hello, World!</h1>\n<p>Body text</p>\n</section>\n";

        self::assertSame($expected, (new HtmlRenderer())->render($document, $source));
    }

    public function testSectionLevelClampsToH6(): void
    {
        $rst = "Deep\n~~~~\n";
        $source = Source::fromString($rst);
        $title = new Title(self::span($rst, 'Deep'), self::text($rst, 'Deep'));
        $section = new Section(ByteSpan::of(0, \strlen($rst)), 7, $title, [], '~', false);
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$section]);

        self::assertSame("<section id=\"deep\">\n<h6>Deep</h6>\n</section>\n", (new HtmlRenderer())->render($document, $source));
    }

    public function testSectionWithEmptySlugOmitsTheId(): void
    {
        $rst = "!!!\n===\n";
        $source = Source::fromString($rst);
        $title = new Title(self::span($rst, '!!!'), self::text($rst, '!!!'));
        $section = new Section(ByteSpan::of(0, \strlen($rst)), 1, $title, [], '=', false);
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$section]);

        self::assertSame("<section>\n<h1>!!!</h1>\n</section>\n", (new HtmlRenderer())->render($document, $source));
    }

    public function testLiteralBlockSlicesTheSource(): void
    {
        $rst = "::\n\n    print(\"hi\")\n";
        $source = Source::fromString($rst);
        $content = self::span($rst, "    print(\"hi\")\n");
        $block = new LiteralBlock(ByteSpan::of(0, \strlen($rst)), $content);
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$block]);

        $expected = "<pre class=\"literal\">    print(&quot;hi&quot;)\n</pre>\n";

        self::assertSame($expected, (new HtmlRenderer())->render($document, $source));
    }

    public function testBlockQuote(): void
    {
        $rst = "    Quoted text\n";
        $source = Source::fromString($rst);
        $paragraph = new Paragraph(self::span($rst, 'Quoted text'), self::text($rst, 'Quoted text'));
        $quote = new BlockQuote(ByteSpan::of(0, \strlen($rst)), [$paragraph]);
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$quote]);

        self::assertSame("<blockquote>\n<p>Quoted text</p>\n</blockquote>\n", (new HtmlRenderer())->render($document, $source));
    }

    public function testBulletList(): void
    {
        $rst = "- one\n- two\n";
        $source = Source::fromString($rst);
        $one = new ListItem(self::span($rst, '- one'), [new Paragraph(self::span($rst, 'one'), self::text($rst, 'one'))]);
        $two = new ListItem(self::span($rst, '- two'), [new Paragraph(self::span($rst, 'two'), self::text($rst, 'two'))]);
        $list = new BulletList(ByteSpan::of(0, \strlen($rst)), '-', [$one, $two]);
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$list]);

        $expected = "<ul>\n<li>\n<p>one</p>\n</li>\n<li>\n<p>two</p>\n</li>\n</ul>\n";

        self::assertSame($expected, (new HtmlRenderer())->render($document, $source));
    }

    public function testEnumeratedListWithDefaultStart(): void
    {
        $rst = "1. one\n";
        $source = Source::fromString($rst);
        $item = new ListItem(self::span($rst, '1. one'), [new Paragraph(self::span($rst, 'one'), self::text($rst, 'one'))]);
        $list = new EnumeratedList(ByteSpan::of(0, \strlen($rst)), EnumerationStyle::Arabic, 1, [$item]);
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$list]);

        self::assertSame("<ol>\n<li>\n<p>one</p>\n</li>\n</ol>\n", (new HtmlRenderer())->render($document, $source));
    }

    public function testEnumeratedListWithStartValue(): void
    {
        $rst = "3. three\n";
        $source = Source::fromString($rst);
        $item = new ListItem(self::span($rst, '3. three'), [new Paragraph(self::span($rst, 'three'), self::text($rst, 'three'))]);
        $list = new EnumeratedList(ByteSpan::of(0, \strlen($rst)), EnumerationStyle::Arabic, 3, [$item]);
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$list]);

        self::assertSame("<ol start=\"3\">\n<li>\n<p>three</p>\n</li>\n</ol>\n", (new HtmlRenderer())->render($document, $source));
    }

    public function testEnumeratedListWithAlphabeticStyleAndStartValue(): void
    {
        $rst = "c. three\n";
        $source = Source::fromString($rst);
        $item = new ListItem(self::span($rst, 'c. three'), [new Paragraph(self::span($rst, 'three'), self::text($rst, 'three'))]);
        $list = new EnumeratedList(ByteSpan::of(0, \strlen($rst)), EnumerationStyle::LowerAlpha, 3, [$item]);
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$list]);

        self::assertSame("<ol type=\"a\" start=\"3\">\n<li>\n<p>three</p>\n</li>\n</ol>\n", (new HtmlRenderer())->render($document, $source));
    }

    public function testAdmonitionDirective(): void
    {
        $rst = ".. note::\n\n    Watch out.\n";
        $source = Source::fromString($rst);
        $body = self::span($rst, 'Watch out.');
        $directive = new Directive(ByteSpan::of(0, \strlen($rst)), 'note', rawBody: $body);
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$directive]);

        $expected = "<div class=\"admonition note\">\n<p>Watch out.</p>\n</div>\n";

        self::assertSame($expected, (new HtmlRenderer())->render($document, $source));
    }

    public function testAdmonitionDirectiveNameIsCaseInsensitive(): void
    {
        $rst = ".. NOTE::\n\n    Watch out.\n";
        $source = Source::fromString($rst);
        $body = self::span($rst, 'Watch out.');
        $directive = new Directive(ByteSpan::of(0, \strlen($rst)), 'NOTE', rawBody: $body);
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$directive]);

        $expected = "<div class=\"admonition note\">\n<p>Watch out.</p>\n</div>\n";

        self::assertSame($expected, (new HtmlRenderer())->render($document, $source));
    }

    public function testAdmonitionDirectiveWithoutBody(): void
    {
        $rst = ".. warning::\n";
        $source = Source::fromString($rst);
        $directive = new Directive(ByteSpan::of(0, \strlen($rst)), 'warning');
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$directive]);

        self::assertSame("<div class=\"admonition warning\"></div>\n", (new HtmlRenderer())->render($document, $source));
    }

    public function testUnknownDirectiveRendersAsCommentPlaceholder(): void
    {
        $rst = ".. toctree::\n";
        $source = Source::fromString($rst);
        $directive = new Directive(ByteSpan::of(0, \strlen($rst)), 'toctree');
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$directive]);

        self::assertSame("<!-- directive: toctree -->\n", (new HtmlRenderer())->render($document, $source));
    }

    public function testDirectivePlaceholderCannotBreakOutOfTheComment(): void
    {
        $rst = ".. x::\n";
        $source = Source::fromString($rst);
        $directive = new Directive(ByteSpan::of(0, \strlen($rst)), 'x--><script>alert(1)</script>');
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$directive]);

        $html = (new HtmlRenderer())->render($document, $source);

        self::assertStringNotContainsString('--><script>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertSame("<!-- directive: x-&gt;&lt;script&gt;alert(1)&lt;/script&gt; -->\n", $html);
    }

    public function testCommentRendersNothing(): void
    {
        $rst = ".. a comment\n";
        $source = Source::fromString($rst);
        $comment = new Comment(ByteSpan::of(0, \strlen($rst)), 'a comment');
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$comment]);

        self::assertSame('', (new HtmlRenderer())->render($document, $source));
    }

    public function testHyperlinkTargetRendersNothing(): void
    {
        $rst = ".. _target: https://example.com\n";
        $source = Source::fromString($rst);
        $target = new HyperlinkTarget(ByteSpan::of(0, \strlen($rst)), 'target', 'https://example.com');
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$target]);

        self::assertSame('', (new HtmlRenderer())->render($document, $source));
    }

    public function testTransition(): void
    {
        $rst = "----\n";
        $source = Source::fromString($rst);
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [new Transition(ByteSpan::of(0, 4))]);

        self::assertSame("<hr>\n", (new HtmlRenderer())->render($document, $source));
    }

    public function testBareTextChildIsEscaped(): void
    {
        $rst = "a < b\n";
        $source = Source::fromString($rst);
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [self::text($rst, 'a < b')]);

        self::assertSame("a &lt; b\n", (new HtmlRenderer())->render($document, $source));
    }

    public function testChildrenConcatenateWithoutWrapper(): void
    {
        $rst = "First\n\nSecond\n";
        $source = Source::fromString($rst);
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [
            new Paragraph(self::span($rst, 'First'), self::text($rst, 'First')),
            new Paragraph(self::span($rst, 'Second'), self::text($rst, 'Second')),
        ]);

        self::assertSame("<p>First</p>\n<p>Second</p>\n", (new HtmlRenderer())->render($document, $source));
    }

    public function testScriptInjectionComesOutInert(): void
    {
        $payload = '<script>alert("xss")</script>';
        $rst = $payload."\n".$payload."\n====\n\n::\n\n    ".$payload."\n";
        $source = Source::fromString($rst);

        $title = new Title(self::span($rst, $payload), self::text($rst, $payload));
        $literalContent = ByteSpan::of(\strlen($rst) - \strlen('    '.$payload."\n"), \strlen('    '.$payload."\n"));
        $section = new Section(ByteSpan::of(0, \strlen($rst)), 1, $title, [
            new Paragraph(self::span($rst, $payload), self::text($rst, $payload)),
            new LiteralBlock($literalContent, $literalContent),
        ], '=', false);
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$section]);

        $html = (new HtmlRenderer())->render($document, $source);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('</script>', $html);
        self::assertSame(3, substr_count($html, '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'));
    }

    public function testExplicitOptionsProduceTheSameOutputInV0(): void
    {
        $rst = "Hello\n";
        $source = Source::fromString($rst);
        $paragraph = new Paragraph(self::span($rst, 'Hello'), self::text($rst, 'Hello'));
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$paragraph]);

        $renderer = new HtmlRenderer();
        $options = new RenderOptions(htmlPolicy: HtmlPolicy::safe()->withAllowedSchemes('https'));

        self::assertSame(
            $renderer->render($document, $source),
            $renderer->render($document, $source, $options),
        );
    }

    public function testManualFootnoteWithoutAGraphRendersItsBodyAsOrdinaryContent(): void
    {
        $rst = 'Body';
        $source = Source::fromString($rst);
        $span = ByteSpan::of(0, 4);
        $paragraph = new Paragraph($span, new Text($span, $rst));
        $footnote = new FootnoteDefinition($span, '1', [$paragraph]);
        $document = new Document($span, [$footnote]);
        $emptySource = Source::fromString('');
        $emptyGraph = ReferenceGraph::fromDocument(
            new Document(ByteSpan::of(0, 0)),
            $emptySource,
        );

        self::assertSame(
            "<p>Body</p>\n",
            new HtmlRenderer()->render($document, $source, references: $emptyGraph),
        );
    }

    public function testFootnoteWithoutASluggableLabelRendersItsBodyWithoutAWrapper(): void
    {
        $rst = 'Body';
        $source = Source::fromString($rst);
        $span = ByteSpan::of(0, 4);
        $paragraph = new Paragraph($span, new Text($span, $rst));
        $footnote = new FootnoteDefinition($span, '!!!', [$paragraph]);
        $document = new Document($span, [$footnote]);

        self::assertSame("<p>Body</p>\n", new HtmlRenderer()->render($document, $source));
    }

    private static function span(string $source, string $fragment): ByteSpan
    {
        $start = strpos($source, $fragment);
        \assert(\is_int($start));

        return ByteSpan::of($start, \strlen($fragment));
    }

    private static function text(string $source, string $fragment): Text
    {
        return new Text(self::span($source, $fragment), $fragment);
    }
}
