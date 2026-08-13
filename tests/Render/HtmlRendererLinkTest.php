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

use Alto\Rst\Node\Document;
use Alto\Rst\Node\HyperlinkTarget;
use Alto\Rst\Node\Inline\Emphasis;
use Alto\Rst\Node\Inline\InlineText;
use Alto\Rst\Node\Inline\InterpretedText;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Render\HtmlPolicy;
use Alto\Rst\Render\HtmlRenderer;
use Alto\Rst\Render\RenderOptions;
use Alto\Rst\Render\RenderState;
use Alto\Rst\Rst;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Link rendering and reference resolution: embedded URIs, named and
 * anonymous references, internal fragment anchors, and the URL policy.
 */
#[CoversClass(HtmlRenderer::class)]
#[CoversClass(RenderState::class)]
final class HtmlRendererLinkTest extends TestCase
{
    public function testEmbeddedUriRendersAnAnchor(): void
    {
        self::assertSame(
            "<p>See <a href=\"https://example.com/a\">docs</a>.</p>\n",
            self::render("See `docs <https://example.com/a>`_.\n"),
        );
    }

    public function testDisallowedSchemeKeepsTheElementWithAnEmptyHref(): void
    {
        self::assertSame(
            "<p><a href=\"\">evil</a></p>\n",
            self::render("`evil <javascript:alert(1)>`_\n"),
        );
    }

    public function testObfuscatedDisallowedSchemeKeepsTheElementWithAnEmptyHref(): void
    {
        self::assertSame(
            "<p><a href=\"\">evil</a></p>\n",
            self::render("`evil <java%0Dscript:alert(1)>`_\n"),
        );
    }

    public function testStandaloneUriRendersAnAnchor(): void
    {
        self::assertSame(
            "<p>Visit <a href=\"https://example.org/\">https://example.org/</a> now.</p>\n",
            self::render("Visit https://example.org/ now.\n"),
        );
    }

    public function testStandaloneUriQueryStringIsAttributeEscaped(): void
    {
        self::assertSame(
            "<p><a href=\"https://example.com/?a=1&amp;b=2\">https://example.com/?a=1&amp;b=2</a></p>\n",
            self::render("https://example.com/?a=1&b=2\n"),
        );
    }

    public function testNamedReferenceResolvesThroughItsTarget(): void
    {
        self::assertSame(
            "<p>See <a href=\"https://example.com\">target</a>.</p>\n",
            self::render("See target_.\n\n.. _target: https://example.com\n"),
        );
    }

    public function testNamedReferenceToADisallowedTargetUrlGetsAnEmptyHref(): void
    {
        $html = self::render("See t_.\n\n.. _t: javascript:alert(1)\n");

        self::assertStringContainsString('<a href="">t</a>', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function testTargetUrlWithAQuoteCannotBreakTheAttribute(): void
    {
        $html = self::render("See t_.\n\n.. _t: https://example.com/\"onmouseover=\"alert(1)\n");

        self::assertStringNotContainsString('/"onmouseover', $html);
        self::assertStringContainsString('href="https://example.com/&quot;onmouseover=&quot;alert(1)"', $html);
    }

    public function testInternalTargetAttachesItsIdToTheFollowingParagraph(): void
    {
        self::assertSame(
            "<p>Jump to <a href=\"#intro\">intro</a>.</p>\n<p id=\"intro\">Intro text.</p>\n",
            self::render("Jump to intro_.\n\n.. _intro:\n\nIntro text.\n"),
        );
    }

    public function testReferenceBeforeItsTargetStillResolves(): void
    {
        $html = self::render("See `anchor point`_ first.\n\nThen _`anchor point` defines it.\n");

        self::assertStringContainsString('<a href="#anchor-point">anchor point</a>', $html);
        self::assertStringContainsString('<span id="anchor-point">anchor point</span>', $html);
    }

    public function testUnresolvedInternalReferenceStaysPlainTextWithoutAnyHref(): void
    {
        self::assertSame(
            "<p>See missing here.</p>\n",
            self::render("See missing_ here.\n"),
        );
    }

    public function testAnonymousReferenceTakesTheNextAnonymousTarget(): void
    {
        self::assertSame(
            "<p><a href=\"https://example.com/anon\">Click</a></p>\n",
            self::render("`Click`__\n\n.. __: https://example.com/anon\n"),
        );
    }

    public function testAnonymousReferenceWithoutATargetStaysPlainText(): void
    {
        self::assertSame("<p>Click here</p>\n", self::render("`Click here`__\n"));
    }

    public function testAliasTargetChainResolvesToTheFinalUrl(): void
    {
        self::assertSame(
            "<p>See <a href=\"https://example.com/b\">a</a>.</p>\n",
            self::render("See a_.\n\n.. _a: b_\n.. _b: https://example.com/b\n"),
        );
    }

    public function testAliasCycleStaysPlainText(): void
    {
        self::assertSame(
            "<p>See a.</p>\n",
            self::render("See a_.\n\n.. _a: b_\n.. _b: a_\n"),
        );
    }

    public function testEmbeddedAliasResolvesThroughTheNamedTarget(): void
    {
        self::assertSame(
            "<p><a href=\"https://example.com/t\">text</a></p>\n",
            self::render("`text <target_>`_\n\n.. _target: https://example.com/t\n"),
        );
    }

    public function testExternalTargetRendersNothingItself(): void
    {
        self::assertSame('', self::render(".. _target: https://example.com\n"));
    }

    public function testInternalTargetWithAnUnsluggableNameRendersNothing(): void
    {
        $rst = ".. _target: \n";
        $source = Source::fromString($rst);
        $target = new HyperlinkTarget(ByteSpan::of(0, \strlen($rst)), '!!!', '');
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$target]);

        self::assertSame('', new HtmlRenderer()->render($document, $source));
    }

    public function testInternalTargetNameIsSluggedIntoASafeId(): void
    {
        $rst = "placeholder\n";
        $source = Source::fromString($rst);
        $target = new HyperlinkTarget(ByteSpan::of(0, 1), 'x"y<z', '');
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [$target]);

        self::assertSame("<span id=\"x-y-z\"></span>\n", new HtmlRenderer()->render($document, $source));
    }

    public function testACustomPolicyRestrictsSchemesForInlineLinks(): void
    {
        $rst = "`m <mailto:a@example.com>`_\n";
        $source = Source::fromString($rst);
        $document = Rst::symfony()->parse($rst)->document();
        $options = new RenderOptions(htmlPolicy: HtmlPolicy::safe()->withAllowedSchemes('https'));

        self::assertSame(
            "<p><a href=\"\">m</a></p>\n",
            new HtmlRenderer()->render($document, $source, $options),
        );
    }

    public function testRendererFallsBackToItsLocalTargetTableWhenTheGraphHasNoInlineData(): void
    {
        self::assertSame(
            '<p>See <a href="https://example.com/final">named</a>, '
            . '<a href="https://example.com/final">embedded</a>, '
            . '<a href="https://example.com/direct">direct</a>, '
            . "<a href=\"#inside\">inside</a>, and missing.</p>\n"
            . "<p><a href=\"https://example.com/anonymous\">anonymous</a></p>\n"
            . "<p><a href=\"https://example.com/final\">alias anonymous</a></p>\n"
            . "<p>orphan</p>\n"
            . "<span id=\"inside\"></span>\n"
            . "<p>Destination.</p>\n",
            self::renderWithoutGraph(
                'See named_, `embedded <alias_>`_, '
                . "`direct <https://example.com/direct>`_, inside_, and missing_.\n\n"
                . "`anonymous`__\n\n"
                . "`alias anonymous`__\n\n"
                . "`orphan`__\n\n"
                . ".. _named: `alias`_\n"
                . ".. _alias: https://example.com/final\n"
                . ".. _inside:\n"
                . ".. __: https://example.com/anonymous\n"
                . ".. __: alias_\n\n"
                . "Destination.\n",
            ),
        );
    }

    public function testFallbackKeepsKnownRoleTitlesAndUnresolvedSubstitutionsVisible(): void
    {
        self::assertSame(
            "<p>Title and |name|.</p>\n",
            self::renderWithoutGraph(
                ":ref:`Title <target>` and |name|.\n\n"
                . ".. |name| replace:: replacement\n",
                Profile::symfony(),
            ),
        );
    }

    public function testInlineTargetWithoutASluggableNameStaysVisible(): void
    {
        self::assertSame("<p>See !!! here.</p>\n", self::render("See _`!!!` here.\n"));
    }

    public function testLocalTargetFallbackRejectsAliasCyclesAndUnsafeUrls(): void
    {
        self::assertSame(
            "<p>a and <a href=\"\">unsafe</a></p>\n",
            self::renderWithoutGraph(
                "a_ and unsafe_\n\n"
                . ".. _a: b_\n"
                . ".. _b: a_\n"
                . ".. _unsafe: javascript:alert(1)\n",
            ),
        );
    }

    public function testRenderStateFallbackResolvesAliasesAndAnonymousTargets(): void
    {
        $state = self::stateWithoutGraph();

        $state->registerTarget('', 'url', 'ignored');
        $state->registerTarget('target', 'url', 'https://example.com/first');
        $state->registerTarget('TARGET', 'url', 'https://example.com/ignored');
        $state->registerTarget('alias', 'alias', 'target');
        $state->registerTarget('loop-a', 'alias', 'loop-b');
        $state->registerTarget('loop-b', 'alias', 'loop-a');

        self::assertSame(['url', 'https://example.com/first'], $state->resolve(' Alias '));
        self::assertNull($state->resolve('missing'));
        self::assertNull($state->resolve('loop-a'));

        $state->pushAnonymousTarget('https://example.com/anonymous');
        $state->pushAnonymousTarget(null);

        self::assertSame('https://example.com/anonymous', $state->nextAnonymousTarget());
        self::assertNull($state->nextAnonymousTarget());
        self::assertNull($state->nextAnonymousTarget());
    }

    public function testRenderStateParsesPhysicalTableSegmentsWithoutCrossLineMarkup(): void
    {
        $state = self::stateWithoutGraph(Source::fromString("*one*\n----\n`two`"));
        $text = new \Alto\Rst\Node\Text(
            ByteSpan::of(0, 17),
            "*one*\n`two`",
            [ByteSpan::of(0, 5), ByteSpan::of(11, 5)],
        );

        $nodes = $state->inlineNodes($text, true);

        self::assertCount(3, $nodes);
        self::assertInstanceOf(Emphasis::class, $nodes[0]);
        self::assertInstanceOf(InlineText::class, $nodes[0]->children()[0]);
        self::assertSame('one', $nodes[0]->children()[0]->text);
        self::assertInstanceOf(InlineText::class, $nodes[1]);
        self::assertSame(' ', $nodes[1]->text);
        self::assertInstanceOf(InterpretedText::class, $nodes[2]);
        self::assertSame('two', $nodes[2]->text);
        self::assertSame($nodes, $state->inlineNodes($text, true));
    }

    private static function render(string $rst): string
    {
        $source = Source::fromString($rst);
        $document = Rst::symfony()->parse($rst)->document();

        return new HtmlRenderer()->render($document, $source);
    }

    private static function renderWithoutGraph(string $rst, ?Profile $profile = null): string
    {
        $source = Source::fromString($rst);
        $document = Rst::symfony()->parse($rst)->document();

        return new HtmlRenderer()->render(
            $document,
            $source,
            null === $profile ? null : new RenderOptions(profile: $profile),
            references: self::stateWithoutGraph()->references,
        );
    }

    private static function stateWithoutGraph(?Source $source = null): RenderState
    {
        $source ??= Source::fromString('');
        $document = new Document(ByteSpan::of(0, 0));
        $references = ReferenceGraph::fromDocument($document, Source::fromString(''));

        return new RenderState($source, HtmlPolicy::safe(), null, $references);
    }
}
