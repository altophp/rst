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

namespace Alto\Rst\Tests\Parser;

use Alto\Rst\Node\BulletList;
use Alto\Rst\Node\Comment;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\DirectiveBodyKind;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversNamespace;

#[CoversNamespace('Alto\\Rst\\Parser')]
final class DirectiveParsingTest extends ParserTestCase
{
    public function testDirectiveWithArgumentOnly(): void
    {
        $result = self::parseRst(".. note:: Take note of this.\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $directive = $children[0];
        self::assertInstanceOf(Directive::class, $directive);
        self::assertSame('note', $directive->name);
        self::assertSame(['Take note of this.'], $directive->arguments);
        self::assertSame([], $directive->options);
        self::assertNull($directive->rawBody);
        self::assertNoProblems($result);
    }

    public function testDirectiveWithOptionsAndBody(): void
    {
        $input = ".. code-block:: php\n   :linenos:\n   :caption: Example\n\n   echo 'hi';\n   echo 'bye';\n";
        $result = self::parseRst($input);
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $directive = $children[0];
        self::assertInstanceOf(Directive::class, $directive);
        self::assertSame('code-block', $directive->name);
        self::assertSame(['php'], $directive->arguments);
        self::assertSame(['linenos' => '', 'caption' => 'Example'], $directive->options);
        self::assertNotNull($directive->rawBody);
        self::assertSame(
            "   echo 'hi';\n   echo 'bye';",
            Source::fromString($input)->slice($directive->rawBody),
        );
        self::assertNoProblems($result);
    }

    public function testOptionOrderIsPreserved(): void
    {
        $result = self::parseRst(".. image:: pic.png\n   :width: 200\n   :alt: A picture\n   :align: center\n");
        $children = $result->document()->children();

        $directive = $children[0];
        self::assertInstanceOf(Directive::class, $directive);
        self::assertSame(['width', 'alt', 'align'], array_keys($directive->options));
        self::assertSame('200', $directive->options['width']);
        self::assertNoProblems($result);
    }

    public function testUnknownDirectiveNameStillParses(): void
    {
        $result = self::parseRst(".. some-unknown-thing:: arg\n\n   body line\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        $directive = $children[0];
        self::assertInstanceOf(Directive::class, $directive);
        self::assertSame('some-unknown-thing', $directive->name);
        self::assertNotNull($directive->rawBody);
        self::assertSame(DirectiveBodyKind::Opaque, $directive->bodyKind);
        self::assertSame([], $directive->children());
        self::assertNoProblems($result);
    }

    public function testNumericOptionNameEndsTheOptionBlock(): void
    {
        $result = self::parseRst(".. note::\n   :123: value\n\n   body\n");
        $directive = $result->document()->children()[0];

        self::assertInstanceOf(Directive::class, $directive);
        self::assertSame([], $directive->options);
        self::assertContains('directive/invalid-option-block', self::problemCodes($result));
    }

    public function testSeveralBlankLinesMaySeparateOptionsFromTheBody(): void
    {
        $result = self::parseRst(
            ".. note::\n"
            . "   :class: special\n"
            . "\n"
            . "\n"
            . "   body\n",
        );
        $directive = $result->document()->children()[0];

        self::assertInstanceOf(Directive::class, $directive);
        self::assertSame(['class' => 'special'], $directive->options);
        self::assertNotNull($directive->rawBody);
    }

    public function testBodyOnlyDirective(): void
    {
        $input = ".. warning::\n\n   Danger ahead.\n\nAfter.\n";
        $result = self::parseRst($input);
        $children = $result->document()->children();

        self::assertCount(2, $children);
        $directive = $children[0];
        self::assertInstanceOf(Directive::class, $directive);
        self::assertSame('warning', $directive->name);
        self::assertSame([], $directive->arguments);
        self::assertNotNull($directive->rawBody);
        self::assertSame('   Danger ahead.', Source::fromString($input)->slice($directive->rawBody));
        self::assertSame(DirectiveBodyKind::Blocks, $directive->bodyKind);
        self::assertCount(1, $directive->children());
        self::assertInstanceOf(Paragraph::class, $directive->children()[0]);
        self::assertNoProblems($result);
    }

    public function testStructuredBodyKeepsNestedBlockNodesAndOriginalSpans(): void
    {
        $input = ".. note::\n\n"
            . "   First paragraph.\n\n"
            . "   - one\n"
            . "   - two\n";
        $result = self::parseRst($input);
        $directive = $result->document()->children()[0];

        self::assertInstanceOf(Directive::class, $directive);
        self::assertSame(DirectiveBodyKind::Blocks, $directive->bodyKind);
        self::assertCount(2, $directive->children());
        self::assertInstanceOf(Paragraph::class, $directive->children()[0]);
        self::assertInstanceOf(BulletList::class, $directive->children()[1]);
        self::assertSame(strpos($input, '   First'), $directive->children()[0]->span()->start);
        self::assertSame(strpos($input, '   - one'), $directive->children()[1]->span()->start);
        self::assertNoProblems($result);
    }

    public function testLiteralDirectiveBodyRemainsRaw(): void
    {
        $input = ".. code:: php\n\n   echo 'hi';\n";
        $result = self::parseRst($input);
        $directive = $result->document()->children()[0];

        self::assertInstanceOf(Directive::class, $directive);
        self::assertSame(DirectiveBodyKind::Literal, $directive->bodyKind);
        self::assertSame([], $directive->children());
        self::assertNotNull($directive->rawBody);
        self::assertSame("   echo 'hi';", Source::fromString($input)->slice($directive->rawBody));
        self::assertNoProblems($result);
    }

    public function testOptionsAfterBlankLineAreStillOptions(): void
    {
        $result = self::parseRst(".. image:: img.png\n\n   :width: 100\n");
        $children = $result->document()->children();

        $directive = $children[0];
        self::assertInstanceOf(Directive::class, $directive);
        self::assertSame(['width' => '100'], $directive->options);
        self::assertNull($directive->rawBody);
        self::assertNoProblems($result);
    }

    public function testBlankLineEndsTheOptionBlockBeforeAFieldListBody(): void
    {
        $input = ".. note::\n   :class: first\n\n   :class: body-field\n";
        $result = self::parseRst($input);
        $directive = $result->document()->children()[0];

        self::assertInstanceOf(Directive::class, $directive);
        self::assertSame(['class' => 'first'], $directive->options);
        self::assertNotNull($directive->rawBody);
        self::assertSame('   :class: body-field', Source::fromString($input)->slice($directive->rawBody));
        self::assertCount(1, $directive->children());
        self::assertInstanceOf(Paragraph::class, $directive->children()[0]);
        self::assertSame(['parser/unsupported-construct'], self::problemCodes($result));
    }

    public function testMultiLineOptionValue(): void
    {
        $result = self::parseRst(".. figure:: pic.png\n   :alt: first part\n      second part\n");
        $children = $result->document()->children();

        $directive = $children[0];
        self::assertInstanceOf(Directive::class, $directive);
        self::assertSame(['alt' => "first part\nsecond part"], $directive->options);
        self::assertNoProblems($result);
    }

    public function testDuplicateOptionIsReportedAndLastValueWins(): void
    {
        $result = self::parseRst(".. image:: pic.png\n   :width: 100\n   :width: 200\n");
        $children = $result->document()->children();

        $directive = $children[0];
        self::assertInstanceOf(Directive::class, $directive);
        self::assertSame(['width' => '200'], $directive->options);
        self::assertSame(['directive/duplicate-option'], self::problemCodes($result));
    }

    public function testNonFieldLineAfterOptionsIsReportedAndBecomesBody(): void
    {
        $input = ".. foo::\n   :opt: value\n   body without blank\n";
        $result = self::parseRst($input);
        $children = $result->document()->children();

        $directive = $children[0];
        self::assertInstanceOf(Directive::class, $directive);
        self::assertSame(['opt' => 'value'], $directive->options);
        self::assertNotNull($directive->rawBody);
        self::assertSame('   body without blank', Source::fromString($input)->slice($directive->rawBody));
        self::assertSame(['directive/invalid-option-block'], self::problemCodes($result));
    }

    public function testDottedDirectiveName(): void
    {
        $result = self::parseRst(".. my.domain:role:: arg\n");
        $children = $result->document()->children();

        $directive = $children[0];
        self::assertInstanceOf(Directive::class, $directive);
        self::assertSame('my.domain:role', $directive->name);
        self::assertNoProblems($result);
    }

    public function testMissingSpaceAfterMarkerIsAComment(): void
    {
        $result = self::parseRst(".. foo::bar\n");
        $children = $result->document()->children();

        self::assertCount(1, $children);
        self::assertInstanceOf(Comment::class, $children[0]);
        self::assertNoProblems($result);
    }

    public function testDirectiveSpanCoversMarkerThroughBody(): void
    {
        $input = ".. note::\n\n   Body.\n\nAfter.\n";
        $result = self::parseRst($input);
        $children = $result->document()->children();

        $directive = $children[0];
        self::assertInstanceOf(Directive::class, $directive);
        self::assertSpan(0, 19, $directive);
    }
}
