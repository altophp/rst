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

use Alto\Rst\Profile\Profile;
use Alto\Rst\Render\HtmlRenderer;
use Alto\Rst\Render\RenderOptions;
use Alto\Rst\Render\RenderState;
use Alto\Rst\Rst;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Inline markup rendering through the real parser: emphasis, strong,
 * inline literals, roles, and document-wide references.
 */
#[CoversClass(HtmlRenderer::class)]
#[CoversClass(RenderState::class)]
final class HtmlRendererInlineTest extends TestCase
{
    public function testEmphasisStrongAndInlineLiteral(): void
    {
        self::assertSame(
            "<p>This is <em>em</em> and <strong>strong</strong> and <code>lit</code>.</p>\n",
            self::render("This is *em* and **strong** and ``lit``.\n"),
        );
    }

    public function testInlineLiteralContentIsEscaped(): void
    {
        self::assertSame(
            "<p><code>&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;</code></p>\n",
            self::render("``<script>alert(\"x\")</script>``\n"),
        );
    }

    public function testNestedMarkupInsideEmphasisRenders(): void
    {
        self::assertSame(
            "<p><em>outer <code>inner</code> tail</em></p>\n",
            self::render("*outer ``inner`` tail*\n"),
        );
    }

    public function testPlainTextStaysEscaped(): void
    {
        self::assertSame("<p>a &lt; b &amp; c</p>\n", self::render("a < b & c\n"));
    }

    public function testDefaultRoleRendersAsCite(): void
    {
        self::assertSame("<p><cite>Title</cite></p>\n", self::render("`Title`\n"));
    }

    public function testUnknownRoleWithoutProfileRendersItsTextEscaped(): void
    {
        self::assertSame(
            "<p>Foo\\Bar&lt;T&gt;</p>\n",
            self::render(":class:`Foo\\\\Bar<T>`\n"),
        );
    }

    public function testSemanticRolesMapToElementsUnderAProfile(): void
    {
        self::assertSame(
            "<p><em>e</em> <strong>s</strong> <code>c</code> <sub>lo</sub> <sup>hi</sup> <cite>t</cite></p>\n",
            self::render(
                ":emphasis:`e` :strong:`s` :literal:`c` :sub:`lo` :sup:`hi` :title:`t`\n",
                Profile::symfony(),
            ),
        );
    }

    public function testCodeRoleContentIsEscapedUnderAProfile(): void
    {
        self::assertSame(
            "<p><code>x &lt; 1 &amp;&amp; y</code></p>\n",
            self::render(":code:`x < 1 && y`\n", Profile::symfony()),
        );
    }

    public function testRefRoleWithExplicitTitleRendersAResolvedLink(): void
    {
        self::assertSame(
            "<p>See <a href=\"#some-target\">Read this</a>.</p>\n"
            . "<span id=\"some-target\"></span>\n"
            . "<section id=\"target-title\">\n<h1>Target title</h1>\n</section>\n",
            self::render(
                "See :ref:`Read this <some-target>`.\n\n.. _some-target:\n\nTarget title\n============\n",
                Profile::symfony(),
            ),
        );
    }

    public function testRefRoleWithoutTitleUsesTheResolvedSectionTitle(): void
    {
        self::assertSame(
            "<p>See <a href=\"#some-target\">Target title</a>.</p>\n"
            . "<span id=\"some-target\"></span>\n"
            . "<section id=\"target-title\">\n<h1>Target title</h1>\n</section>\n",
            self::render(
                "See :ref:`some-target`.\n\n.. _some-target:\n\nTarget title\n============\n",
                Profile::symfony(),
            ),
        );
    }

    public function testKnownPhpRolesRenderAsCodeUnderTheSymfonyProfile(): void
    {
        self::assertSame(
            "<p><code>Foo\\Bar</code> and <code>bar()</code></p>\n",
            self::render(":class:`Foo\\\\Bar` and :method:`bar()`\n", Profile::symfony()),
        );
    }

    public function testUnknownRoleUnderAProfileRendersItsTextEscaped(): void
    {
        self::assertSame(
            "<p>&lt;raw&gt;</p>\n",
            self::render(":nosuchrole:`<raw>`\n", Profile::symfony()),
        );
    }

    public function testFootnoteAndCitationReferencesRenderWithBacklinks(): void
    {
        self::assertSame(
            '<p>See <a id="footnote-reference-1" class="footnote-reference" href="#footnote-1">[1]</a>'
            . " and <a id=\"citation-reference-cit2002\" class=\"citation-reference\" href=\"#citation-cit2002\">[CIT2002]</a>.</p>\n"
            . "<aside id=\"footnote-1\" class=\"footnote\">\n"
            . "<span class=\"label\">[1]</span><span class=\"backrefs\"><a class=\"backref\" href=\"#footnote-reference-1\">back</a></span>\n"
            . "<p>Footnote body.</p>\n</aside>\n"
            . "<aside id=\"citation-cit2002\" class=\"citation\">\n"
            . "<span class=\"label\">[CIT2002]</span><span class=\"backrefs\"><a class=\"backref\" href=\"#citation-reference-cit2002\">back</a></span>\n"
            . "<p>Citation body.</p>\n</aside>\n",
            self::render(
                "See [1]_ and [CIT2002]_.\n\n.. [1] Footnote body.\n\n.. [CIT2002] Citation body.\n",
            ),
        );
    }

    public function testSubstitutionReferenceExpandsItsReplacement(): void
    {
        self::assertSame(
            "<p>Use Alto Rst here.</p>\n",
            self::render("Use |name| here.\n\n.. |name| replace:: Alto Rst\n"),
        );
    }

    public function testNestedSubstitutionExpandsRecursively(): void
    {
        self::assertSame(
            "<p>Use Before middle after.</p>\n",
            self::render(
                ".. |outer| replace:: Before |inner| after\n"
                . ".. |inner| replace:: middle\n\n"
                . "Use |outer|.\n",
            ),
        );
    }

    public function testLinkedSubstitutionKeepsItsReplacementWhenTheLinkIsUnresolved(): void
    {
        self::assertSame(
            "<p>Label</p>\n",
            self::render(".. |name| replace:: Label\n\n|name|_\n"),
        );
    }

    public function testLinkedSubstitutionAppliesUrlPolicyAndInternalAnchors(): void
    {
        self::assertSame(
            "<p><a href=\"\">Label</a></p>\n",
            self::render(
                ".. |name| replace:: Label\n"
                . ".. _name: javascript:alert(1)\n\n"
                . "|name|_\n",
            ),
        );
        self::assertSame(
            "<p><a href=\"#name\">Label</a></p>\n"
            . "<p id=\"name\">Destination.</p>\n",
            self::render(
                ".. |name| replace:: Label\n\n"
                . "|name|_\n\n"
                . ".. _name:\n\n"
                . "Destination.\n",
            ),
        );
    }

    public function testSubstitutionCanIntroduceAResolvedHyperlink(): void
    {
        self::assertSame(
            "<p>Visit <a href=\"https://example.com/\">Example</a>.</p>\n",
            self::render(
                ".. |site| replace:: `Example`_\n"
                . ".. _Example: https://example.com/\n\n"
                . "Visit |site|.\n",
            ),
        );
    }

    public function testLinkedSubstitutionsPreserveNamedAndAnonymousLinks(): void
    {
        self::assertSame(
            "<p>Named <a href=\"https://example.com/\">Example</a>.</p>\n",
            self::render(
                ".. |name| replace:: Example\n"
                . ".. _name: https://example.com/\n\n"
                . "Named |name|_.\n",
            ),
        );
        self::assertSame(
            "<p>Anonymous <a href=\"https://example.com/\">Example</a>.</p>\n",
            self::render(
                ".. |name| replace:: Example\n"
                . ".. __: https://example.com/\n\n"
                . "Anonymous |name|__.\n",
            ),
        );
    }

    public function testStandardSubstitutionDirectiveKindsRenderSemantically(): void
    {
        self::assertSame(
            "<p>Use <img src=\"logo.png\" alt=\"Logo\">.</p>\n",
            self::render(".. |logo| image:: logo.png\n   :alt: Logo\n\nUse |logo|.\n"),
        );
        self::assertSame(
            "<p>Use ©.</p>\n",
            self::render(".. |copy| unicode:: 0xA9\n\nUse |copy|.\n"),
        );
        self::assertSame(
            "<p>Use first second <em>line</em>.</p>\n",
            self::render(".. |x| replace:: first\n   second *line*\n\nUse |x|.\n"),
        );
    }

    public function testAutomaticFootnoteWithoutAReferenceStillRendersItsLabel(): void
    {
        self::assertSame(
            "<aside id=\"footnote-1\" class=\"footnote\">\n"
            . "<span class=\"label\">[1]</span>\n"
            . "<p>Orphan.</p>\n</aside>\n",
            self::render(".. [#] Orphan.\n"),
        );
    }

    public function testInternalTargetsAttachToAnyBlockAndKeepDistinctIds(): void
    {
        self::assertSame(
            "<p>See <a href=\"#a\">a</a> and <a href=\"#b\">b</a>.</p>\n"
            . "<span id=\"a\"></span>\n"
            . "<span id=\"b\"></span>\n"
            . "<ul>\n<li>\n<p>item</p>\n</li>\n</ul>\n",
            self::render("See a_ and b_.\n\n.. _a:\n.. _b:\n\n- item\n"),
        );
    }

    public function testAnonymousInternalTargetGetsASyntheticId(): void
    {
        self::assertSame(
            "<p><a href=\"#target-1\">Jump</a> now.</p>\n"
            . "<p id=\"target-1\">Destination paragraph.</p>\n",
            self::render("Jump__ now.\n\n.. __:\n\nDestination paragraph.\n"),
        );
    }

    public function testMultipleFootnoteReferencesProduceOrderedBacklinks(): void
    {
        $html = self::render("See [1]_ once and [1]_ twice.\n\n.. [1] Note body.\n");

        self::assertStringContainsString('id="footnote-reference-1"', $html);
        self::assertStringContainsString('id="footnote-reference-1-1"', $html);
        self::assertStringContainsString('href="#footnote-reference-1">back</a>', $html);
        self::assertStringContainsString('href="#footnote-reference-1-1">back2</a>', $html);
    }

    public function testInlineTargetRendersASpanWithItsSlugId(): void
    {
        self::assertSame(
            "<p>See <span id=\"anchor-point\">anchor point</span> here.</p>\n",
            self::render("See _`anchor point` here.\n"),
        );
    }

    public function testTitleTextGoesThroughTheInlinePass(): void
    {
        self::assertSame(
            "<section id=\"the-code-option\">\n<h1>The <code>code</code> option</h1>\n</section>\n",
            self::render("The ``code`` option\n===================\n"),
        );
    }

    public function testMultiLineParagraphJoinsWithASingleSpace(): void
    {
        self::assertSame(
            "<p>First line <em>and second</em> line.</p>\n",
            self::render("First line *and\nsecond* line.\n"),
        );
    }

    private static function render(string $rst, ?Profile $profile = null): string
    {
        $source = Source::fromString($rst);
        $document = Rst::symfony()->parse($rst)->document();

        return new HtmlRenderer()->render($document, $source, new RenderOptions(profile: $profile));
    }
}
