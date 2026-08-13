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
use Alto\Rst\Convert\Markdown\MarkdownReader;
use Alto\Rst\Convert\MarkdownToRst;
use Alto\Rst\Convert\RstToMarkdown;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Source\Source;
use Alto\Rst\Tests\Convert\Support\MdShape;
use Alto\Rst\Tests\Convert\Support\RstShape;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip stability: rst -> md -> rst and md -> rst -> md must keep the
 * semantic tree shape. Byte equality is not the contract; the reparsed
 * shape is.
 */
#[CoversNamespace('Alto\\Rst\\Convert')]
final class ConvertRoundTripTest extends TestCase
{
    private static function rstToMd(string $rst): string
    {
        $source = Source::fromString($rst);
        $document = new BlockParser()->parse($source)->document();

        return new RstToMarkdown()->convert($document, $source, Profile::symfony(), ConversionOptions::symfony())->output;
    }

    private static function mdToRst(string $markdown): string
    {
        $document = new MarkdownReader()->read($markdown);

        return new MarkdownToRst()->convert($document, ConversionOptions::symfony())->output;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rstDocuments(): iterable
    {
        yield 'sections and inline markup' => [<<<'RST'
            Title
            =====

            A paragraph with *emphasis*, **strong**, and ``literal()``.

            Sub Section
            -----------

            More prose here.
            RST];

        yield 'reference and inline links' => [<<<'RST'
            See Symfony_, `the manual`_, and `inline docs <https://example.com/inline>`_.

            Browse https://example.com/bare for more.

            .. _Symfony: https://symfony.com
            .. _the manual: https://example.com/manual
            RST];

        yield 'code blocks and literal blocks' => [<<<'RST'
            Install it:

            .. code-block:: terminal

                $ composer require alto/rst

            Configure::

                framework:
                    secret: true
            RST];

        yield 'admonitions' => [<<<'RST'
            .. note::

                A *useful* note.

            .. warning::

                With two paragraphs.

                Second one.
            RST];

        yield 'version directives' => [<<<'RST'
            .. versionadded:: 2.20

                The thing was added.

            .. deprecated:: 3.0

                The thing left.
            RST];

        yield 'simple table' => [<<<'RST'
            =====  ========
            Name   Meaning
            =====  ========
            a      first
            b      second
            =====  ========
            RST];

        yield 'lists' => [<<<'RST'
            - one
            - two

            1. first
            2. second

            * outer

              inner paragraph
            RST];

        yield 'nested lists' => [<<<'RST'
            - top level

              - nested one
              - nested two

            - second top
            RST];

        yield 'transitions comments and quotes' => [<<<'RST'
            before

            ----

            .. a comment line

            paragraph

                an indented quote
            RST];
    }

    #[DataProvider('rstDocuments')]
    public function testRstSurvivesARoundTripThroughMarkdown(string $rst): void
    {
        $markdown = self::rstToMd($rst);
        $back = self::mdToRst($markdown);

        self::assertSame(RstShape::of($rst), RstShape::of($back), "Markdown was:\n" . $markdown . "\nRST back:\n" . $back);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function markdownDocuments(): iterable
    {
        yield 'headings and inline markup' => [<<<'MD'
            # Title

            A paragraph with *emphasis*, **strong**, and `literal()`.

            ## Sub Section

            More prose here.
            MD];

        yield 'links in both styles' => [<<<'MD'
            See [Symfony][Symfony] and [the docs](https://example.com/inline).

            Browse <https://example.com/bare> for more.

            [Symfony]: https://symfony.com
            MD];

        yield 'code blocks' => [<<<'MD'
            Install it:

            ```terminal
            $ composer require alto/rst
            ```

            ```
            plain literal
            ```
            MD];

        yield 'callouts' => [<<<'MD'
            > [!NOTE]
            >
            > A *useful* note.

            > [!CAUTION]
            >
            > First paragraph.
            >
            > Second paragraph.
            MD];

        yield 'version blockquote' => [<<<'MD'
            > **New in version 2.20**
            >
            > The thing was added.
            MD];

        yield 'pipe table' => [<<<'MD'
            | Name | Meaning |
            | --- | --- |
            | a | first |
            | b | second |
            MD];

        yield 'lists' => [<<<'MD'
            - one
            - two

            1. first
            2. second

            * outer

              inner paragraph
            MD];

        yield 'break comment image' => [<<<'MD'
            before

            ---

            <!-- a comment line -->

            ![A map](pictures/map.png)
            MD];
    }

    #[DataProvider('markdownDocuments')]
    public function testMarkdownSurvivesARoundTripThroughRst(string $markdown): void
    {
        $rst = self::mdToRst($markdown . "\n");
        $back = self::rstToMd($rst);

        self::assertSame(MdShape::of($markdown . "\n"), MdShape::of($back), "RST was:\n" . $rst . "\nMarkdown back:\n" . $back);
    }
}
