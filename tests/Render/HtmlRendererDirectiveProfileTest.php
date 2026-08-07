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

use Alto\Rst\Extension\DirectiveRenderContext;
use Alto\Rst\Extension\Symfony\ConfigurationBlockHandler;
use Alto\Rst\Extension\Symfony\ScreencastHandler;
use Alto\Rst\Node\Comment;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\DirectiveBodyKind;
use Alto\Rst\Node\Document;
use Alto\Rst\Profile\Profile;
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
 * Profile-driven directive rendering: code blocks, admonitions, version
 * notes, images and figures, and the placeholders everything else keeps.
 */
#[CoversClass(HtmlRenderer::class)]
#[CoversClass(RenderState::class)]
#[CoversClass(DirectiveRenderContext::class)]
#[CoversClass(ConfigurationBlockHandler::class)]
#[CoversClass(ScreencastHandler::class)]
final class HtmlRendererDirectiveProfileTest extends TestCase
{
    public function testCodeBlockRendersALanguageClassAndEscapedBody(): void
    {
        self::assertSame(
            "<pre><code class=\"language-php\">echo 1 &lt; 2;</code></pre>\n",
            self::render(".. code-block:: php\n\n    echo 1 < 2;\n"),
        );
    }

    public function testCodeBlockPreservesHtmlTwigLanguageName(): void
    {
        self::assertSame(
            "<pre><code class=\"language-html+twig\">&lt;div&gt;{{ x }}&lt;/div&gt;</code></pre>\n",
            self::render(".. code-block:: html+twig\n\n    <div>{{ x }}</div>\n"),
        );
    }

    public function testCodeBlockKeepsTerminalAsItsOwnLanguage(): void
    {
        self::assertSame(
            "<pre><code class=\"language-terminal\">\$ composer install</code></pre>\n",
            self::render(".. code-block:: terminal\n\n    \$ composer install\n"),
        );
    }

    public function testSourcecodeIsAnAliasOfCodeBlock(): void
    {
        self::assertSame(
            "<pre><code class=\"language-yaml\">key: value</code></pre>\n",
            self::render(".. sourcecode:: yaml\n\n    key: value\n"),
        );
    }

    public function testCodeBlockWithoutALanguageOmitsTheClass(): void
    {
        self::assertSame(
            "<pre><code>body</code></pre>\n",
            self::render(".. code-block::\n\n    body\n"),
        );
    }

    public function testCodeBlockBodyIsDedentedButKeepsRelativeIndentation(): void
    {
        self::assertSame(
            "<pre><code class=\"language-php\">if (\$x) {\n    run();\n}</code></pre>\n",
            self::render(".. code-block:: php\n\n    if (\$x) {\n        run();\n    }\n"),
        );
    }

    public function testCodeBlockLanguageCannotBreakTheClassAttribute(): void
    {
        $html = self::render(".. code-block:: twig\" onmouseover=\"alert(1)\n\n    body\n");

        self::assertStringNotContainsString('twig" onmouseover', $html);
        self::assertStringContainsString('class="language-twig&quot; onmouseover=&quot;alert(1)"', $html);
    }

    public function testAdmonitionGetsATitleParagraphUnderAProfile(): void
    {
        self::assertSame(
            "<div class=\"admonition note\">\n<p class=\"admonition-title\">Note</p>\n<p>Watch out.</p>\n</div>\n",
            self::render(".. note::\n\n    Watch out.\n"),
        );
    }

    public function testAdmonitionRendersItsStructuredBlockBody(): void
    {
        self::assertSame(
            "<div class=\"admonition note\">\n"
            ."<p class=\"admonition-title\">Note</p>\n"
            ."<p>Choose one:</p>\n"
            ."<ul>\n"
            ."<li>\n<p>first</p>\n</li>\n"
            ."<li>\n<p>second</p>\n</li>\n"
            ."</ul>\n"
            ."</div>\n",
            self::render(
                ".. note::\n\n"
                ."    Choose one:\n\n"
                ."    - first\n"
                ."    - second\n",
            ),
        );
    }

    public function testSeealsoRendersAsAnAdmonitionWithItsOwnTitle(): void
    {
        self::assertSame(
            "<div class=\"admonition seealso\">\n<p class=\"admonition-title\">See also</p>\n<p>Other doc.</p>\n</div>\n",
            self::render(".. seealso::\n\n    Other doc.\n"),
        );
    }

    public function testGenericAdmonitionUsesItsArgumentAsTitle(): void
    {
        self::assertSame(
            "<div class=\"admonition admonition\">\n<p class=\"admonition-title\">Fun &lt;fact&gt;</p>\n<p>Body.</p>\n</div>\n",
            self::render(".. admonition:: Fun <fact>\n\n    Body.\n"),
        );
    }

    public function testAdmonitionWithoutABodyKeepsOnlyTheTitle(): void
    {
        self::assertSame(
            "<div class=\"admonition warning\">\n<p class=\"admonition-title\">Warning</p>\n</div>\n",
            self::render(".. warning::\n"),
        );
    }

    public function testVersionaddedRendersAVersionNote(): void
    {
        self::assertSame(
            "<div class=\"version-note versionadded\">\n<p>New in version 2.8</p>\n<p>The thing arrived.</p>\n</div>\n",
            self::render(".. versionadded:: 2.8\n\n    The thing arrived.\n"),
        );
    }

    public function testDeprecatedRendersItsPrefixWithoutABody(): void
    {
        self::assertSame(
            "<div class=\"version-note deprecated\">\n<p>Deprecated since version 3.0</p>\n</div>\n",
            self::render(".. deprecated:: 3.0\n"),
        );
    }

    public function testVersionchangedRendersItsPrefix(): void
    {
        self::assertSame(
            "<div class=\"version-note versionchanged\">\n<p>Changed in version 4.1</p>\n</div>\n",
            self::render(".. versionchanged:: 4.1\n"),
        );
    }

    public function testVersionArgumentIsEscaped(): void
    {
        $html = self::render(".. versionadded:: <script>alert(1)</script>\n");

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('New in version &lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testImageRendersWithAltFromItsOption(): void
    {
        self::assertSame(
            "<img src=\"/img/a.png\" alt=\"An image\">\n",
            self::render(".. image:: /img/a.png\n    :alt: An image\n"),
        );
    }

    public function testImageWithoutAltOmitsTheAttribute(): void
    {
        self::assertSame(
            "<img src=\"https://example.com/a.png\">\n",
            self::render(".. image:: https://example.com/a.png\n"),
        );
    }

    public function testImageWithADisallowedSchemeGetsAnEmptySrc(): void
    {
        self::assertSame(
            "<img src=\"\">\n",
            self::render(".. image:: javascript:alert(1)\n"),
        );
    }

    public function testImageWithAnObfuscatedDisallowedSchemeGetsAnEmptySrc(): void
    {
        self::assertSame(
            "<img src=\"\">\n",
            self::render(".. image:: java%0Dscript:alert(1)\n"),
        );
    }

    public function testImageAltCannotBreakTheAttribute(): void
    {
        $html = self::render(".. image:: /a.png\n    :alt: x\" onerror=\"alert(1)\n");

        self::assertStringNotContainsString('x" onerror', $html);
        self::assertStringContainsString('alt="x&quot; onerror=&quot;alert(1)"', $html);
    }

    public function testFigureWrapsTheImageAndRendersTheFirstParagraphAsCaption(): void
    {
        self::assertSame(
            "<figure>\n"
            ."<img src=\"https://example.com/a.png\" alt=\"Fig\">\n"
            ."<figcaption>The <em>caption</em>.</figcaption>\n"
            ."<p>A legend paragraph.</p>\n"
            ."</figure>\n",
            self::render(
                ".. figure:: https://example.com/a.png\n"
                ."    :alt: Fig\n\n"
                ."    The *caption*.\n\n"
                ."    A legend paragraph.\n",
            ),
        );
    }

    public function testFigureWithoutABodyOmitsTheCaption(): void
    {
        self::assertSame(
            "<figure>\n<img src=\"/a.png\">\n</figure>\n",
            self::render(".. figure:: /a.png\n"),
        );
    }

    public function testFileReadingDirectivesStayInertComments(): void
    {
        self::assertSame(
            "<!-- directive: include -->\n",
            self::render(".. include:: secrets.txt\n"),
        );
    }

    public function testSymfonyConfigurationBlockRendersNestedCodeBlocks(): void
    {
        self::assertSame(
            "<div class=\"configuration-block\">\n"
            ."<pre><code class=\"language-yaml\">key: value</code></pre>\n"
            ."<pre><code class=\"language-php\">return [];</code></pre>\n"
            ."</div>\n",
            self::render(
                ".. configuration-block::\n\n"
                ."    .. code-block:: yaml\n\n"
                ."        key: value\n\n"
                ."    .. code-block:: php\n\n"
                ."        return [];\n",
            ),
        );
    }

    public function testEmptySymfonyConfigurationBlockRendersAnEmptyGroup(): void
    {
        self::assertSame(
            "<div class=\"configuration-block\">\n</div>\n",
            self::render(".. configuration-block::\n"),
        );
    }

    public function testLegacyConfigurationBlockReparsesItsOpaqueBody(): void
    {
        $body = "    .. code-block:: yaml\n\n        key: value\n";
        $source = Source::fromString($body);
        $directive = new Directive(
            ByteSpan::of(0, \strlen($body)),
            'configuration-block',
            rawBody: ByteSpan::of(0, \strlen($body)),
            bodyKind: DirectiveBodyKind::Opaque,
        );

        self::assertSame(
            "<div class=\"configuration-block\">\n"
            ."<pre><code class=\"language-yaml\">key: value</code></pre>\n"
            ."</div>\n",
            new ConfigurationBlockHandler()->renderHtml(
                $directive,
                $source,
                Profile::symfony(),
                HtmlPolicy::safe(),
                new DirectiveRenderContext(static fn (): string => self::fail('Legacy bodies use the compatibility parser.')),
            ),
        );
    }

    public function testLegacyConfigurationBlockWithoutABodyRendersAnEmptyGroup(): void
    {
        $source = Source::fromString('');
        $directive = new Directive(
            ByteSpan::of(0, 0),
            'configuration-block',
            bodyKind: DirectiveBodyKind::Opaque,
        );

        self::assertSame(
            "<div class=\"configuration-block\">\n</div>\n",
            new ConfigurationBlockHandler()->renderHtml(
                $directive,
                $source,
                Profile::symfony(),
                HtmlPolicy::safe(),
                new DirectiveRenderContext(static fn (): string => self::fail('Legacy bodies use the compatibility parser.')),
            ),
        );
    }

    public function testLegacyConfigurationBlockAcceptsAnAlreadyDedentedBody(): void
    {
        $body = "Plain body.\n";
        $source = Source::fromString($body);
        $directive = new Directive(
            ByteSpan::of(0, \strlen($body)),
            'configuration-block',
            rawBody: ByteSpan::of(0, \strlen($body)),
            bodyKind: DirectiveBodyKind::Opaque,
        );

        self::assertSame(
            "<div class=\"configuration-block\">\n<p>Plain body.</p>\n</div>\n",
            new ConfigurationBlockHandler()->renderHtml(
                $directive,
                $source,
                Profile::symfony(),
                HtmlPolicy::safe(),
                new DirectiveRenderContext(static fn (): string => self::fail('Legacy bodies use the compatibility parser.')),
            ),
        );
    }

    public function testLegacyOpaqueDirectiveBodiesRemainRenderable(): void
    {
        self::assertSame(
            "<div class=\"admonition note\">\n"
            ."<p class=\"admonition-title\">Note</p>\n"
            ."<p>Legacy body.\n</p>\n"
            ."</div>\n",
            self::renderManualDirective(
                "    Legacy body.\n",
                static fn (ByteSpan $span): Directive => new Directive(
                    $span,
                    'note',
                    rawBody: $span,
                    bodyKind: DirectiveBodyKind::Opaque,
                ),
                Profile::docutils(),
            ),
        );

        self::assertSame(
            "<div class=\"version-note versionadded\">\n"
            ."<p>New in version 8.0</p>\n"
            ."<p>Legacy details.\n</p>\n"
            ."</div>\n",
            self::renderManualDirective(
                "Legacy details.\n",
                static fn (ByteSpan $span): Directive => new Directive(
                    $span,
                    'versionadded',
                    ['8.0'],
                    rawBody: $span,
                    bodyKind: DirectiveBodyKind::Opaque,
                ),
                Profile::sphinx(),
            ),
        );

        self::assertSame(
            "<figure>\n"
            ."<img src=\"diagram.svg\">\n"
            ."<figcaption>Legacy caption.</figcaption>\n"
            ."</figure>\n",
            self::renderManualDirective(
                "\n    Legacy caption.\n",
                static fn (ByteSpan $span): Directive => new Directive(
                    $span,
                    'figure',
                    ['diagram.svg'],
                    rawBody: $span,
                    bodyKind: DirectiveBodyKind::Opaque,
                ),
                Profile::docutils(),
            ),
        );
    }

    public function testStructuredFigureKeepsANonParagraphFirstChildInItsBody(): void
    {
        $source = Source::fromString('comment');
        $span = ByteSpan::of(0, 7);
        $directive = new Directive(
            $span,
            'figure',
            ['diagram.svg'],
            rawBody: $span,
            bodyKind: DirectiveBodyKind::Blocks,
            body: [new Comment($span, 'comment')],
        );
        $document = new Document($span, [$directive]);

        self::assertSame(
            "<figure>\n<img src=\"diagram.svg\">\n</figure>\n",
            new HtmlRenderer()->render(
                $document,
                $source,
                new RenderOptions(profile: Profile::docutils()),
            ),
        );
    }

    public function testSymfonyScreencastRendersASemanticAside(): void
    {
        self::assertSame(
            "<aside class=\"screencast\">\n"
            ."<p class=\"screencast-title\">Screencast</p>\n"
            ."<p>Watch the <a href=\"https://example.test\">series</a>.</p>\n"
            ."</aside>\n",
            self::render(
                ".. screencast::\n\n"
                ."    Watch the `series <https://example.test>`_.\n",
            ),
        );
    }

    public function testKnownButUnmappedDirectiveKeepsThePlaceholder(): void
    {
        self::assertSame(
            "<!-- directive: toctree -->\n",
            self::render(".. toctree::\n\n    page\n"),
        );
    }

    public function testUnknownDirectiveKeepsThePlaceholder(): void
    {
        self::assertSame(
            "<!-- directive: nosuch -->\n",
            self::render(".. nosuch::\n"),
        );
    }

    public function testDirectiveTheProfileDoesNotKnowKeepsThePlaceholder(): void
    {
        self::assertSame(
            "<!-- directive: toctree -->\n",
            self::renderWith(Profile::docutils(), ".. toctree::\n\n    guide\n"),
        );
    }

    public function testWithoutAProfileTheV0BehaviorIsKept(): void
    {
        self::assertSame(
            "<div class=\"admonition note\">\n<p>    Watch out.</p>\n</div>\n<!-- directive: code-block -->\n",
            self::renderWith(null, ".. note::\n\n    Watch out.\n\n.. code-block:: php\n\n    echo 1;\n"),
        );
    }

    private static function render(string $rst): string
    {
        return self::renderWith(Profile::symfony(), $rst);
    }

    private static function renderWith(?Profile $profile, string $rst): string
    {
        $source = Source::fromString($rst);
        $document = Rst::symfony()->parse($rst)->document();

        return new HtmlRenderer()->render($document, $source, new RenderOptions(profile: $profile));
    }

    /**
     * @param \Closure(ByteSpan): Directive $directive
     */
    private static function renderManualDirective(string $body, \Closure $directive, Profile $profile): string
    {
        $source = Source::fromString($body);
        $span = ByteSpan::of(0, \strlen($body));
        $document = new Document($span, [$directive($span)]);

        return new HtmlRenderer()->render(
            $document,
            $source,
            new RenderOptions(profile: $profile),
        );
    }
}
