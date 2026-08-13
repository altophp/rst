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
use Alto\Rst\Node\Document;
use Alto\Rst\Node\EnumeratedList;
use Alto\Rst\Node\HyperlinkTarget;
use Alto\Rst\Node\LiteralBlock;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Section;
use Alto\Rst\Node\Transition;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Parser\ParseResult;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;

#[CoversNamespace('Alto\\Rst\\Parser')]
#[CoversClass(ParseResult::class)]
final class BlockParserIntegrationTest extends ParserTestCase
{
    public function testRealisticDocument(): void
    {
        $input = "=====\nTitle\n=====\n\n"
            . "Intro paragraph\non two lines.\n\n"
            . "Section A\n=========\n\n"
            . "Some code::\n\n    line one\n      line two\n\n"
            . "- first item\n- second item\n  continues\n\n"
            . "1. one\n2. two\n\n"
            . ".. code-block:: php\n   :linenos:\n\n   echo \"hi\";\n\n"
            . ".. _target: https://example.com/\n\n"
            . ".. just a comment\n\n"
            . "Subsection\n----------\n\n"
            . "   A block quote.\n\n"
            . "----\n\n"
            . "Closing paragraph.\n";

        $result = self::parseRst($input);

        self::assertNoProblems($result);

        $document = $result->document();
        self::assertSame(0, $document->span()->start);
        self::assertSame(\strlen($input), $document->span()->end());

        $children = $document->children();
        self::assertCount(1, $children);

        $title = $children[0];
        self::assertInstanceOf(Section::class, $title);
        self::assertSame(1, $title->level);
        self::assertTrue($title->hasOverline);
        self::assertSame('Title', $title->title->text->text);

        $titleBody = $title->body();
        self::assertCount(2, $titleBody);
        self::assertInstanceOf(Paragraph::class, $titleBody[0]);
        self::assertSame("Intro paragraph\non two lines.", $titleBody[0]->text->text);

        $sectionA = $titleBody[1];
        self::assertInstanceOf(Section::class, $sectionA);
        self::assertSame(2, $sectionA->level);
        self::assertSame('=', $sectionA->adornment);
        self::assertFalse($sectionA->hasOverline);

        $body = $sectionA->body();
        self::assertCount(8, $body);
        self::assertInstanceOf(Paragraph::class, $body[0]);
        self::assertSame('Some code:', $body[0]->text->text);
        self::assertInstanceOf(LiteralBlock::class, $body[1]);
        self::assertSame(
            "    line one\n      line two",
            Source::fromString($input)->slice($body[1]->content),
        );

        $bullets = $body[2];
        self::assertInstanceOf(BulletList::class, $bullets);
        self::assertCount(2, $bullets->children());

        $enumerated = $body[3];
        self::assertInstanceOf(EnumeratedList::class, $enumerated);
        self::assertSame(1, $enumerated->start);
        self::assertCount(2, $enumerated->children());

        $directive = $body[4];
        self::assertInstanceOf(Directive::class, $directive);
        self::assertSame('code-block', $directive->name);
        self::assertSame(['php'], $directive->arguments);
        self::assertSame(['linenos' => ''], $directive->options);
        self::assertNotNull($directive->rawBody);
        self::assertSame('   echo "hi";', Source::fromString($input)->slice($directive->rawBody));

        $target = $body[5];
        self::assertInstanceOf(HyperlinkTarget::class, $target);
        self::assertSame('target', $target->name);
        self::assertSame('https://example.com/', $target->target);

        self::assertInstanceOf(Comment::class, $body[6]);

        $subsection = $body[7];
        self::assertInstanceOf(Section::class, $subsection);
        self::assertSame(3, $subsection->level);
        self::assertSame('-', $subsection->adornment);

        $subsectionBody = $subsection->body();
        self::assertCount(3, $subsectionBody);
        self::assertInstanceOf(Transition::class, $subsectionBody[1]);
        self::assertInstanceOf(Paragraph::class, $subsectionBody[2]);

        self::assertSame(\strlen($input) - 1, $title->span()->end());
    }

    public function testEveryNodeSpanStaysWithinTheDocument(): void
    {
        $input = "Title\n=====\n\npara\n\n- a\n- b\n\n.. note:: x\n\n    quote\n";
        $result = self::parseRst($input);
        $document = $result->document();
        $length = \strlen($input);

        foreach ($document->descendants() as $node) {
            self::assertGreaterThanOrEqual(0, $node->span()->start);
            self::assertLessThanOrEqual($length, $node->span()->end());
        }
    }

    public function testMalformedKitchenSinkNeverThrows(): void
    {
        $input = "====\nBroken overline\n\n"
            . "Deep\n~~~~\n\n"
            . "Code::\nno blank\n\n"
            . "- one\n* two\nplain\n\n"
            . "1. a\n3. b\n\n"
            . "::\n\nunindented\n";

        $result = self::parseRst($input);

        self::assertInstanceOf(Document::class, $result->document());
        $codes = self::problemCodes($result);
        self::assertContains('section/missing-underline', $codes);
        self::assertContains('list/mixed-markers', $codes);
        self::assertContains('list/missing-blank-line', $codes);
        self::assertContains('literal/missing-content', $codes);
    }

    public function testEmptyAndBlankInputs(): void
    {
        foreach (['', "\n", "   \n\n  \n", "\xEF\xBB\xBFBOM only text\n"] as $input) {
            $result = self::parseRst($input);
            self::assertInstanceOf(Document::class, $result->document());
        }
    }

    public function testConformanceCorpusParsesWithoutErrorsAbsentFromTheOracle(): void
    {
        $files = glob(__DIR__ . '/../fixtures/conformance/*/*.rst');

        self::assertIsArray($files);
        self::assertNotSame([], $files);

        $parser = new BlockParser();

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            self::assertIsString($contents);

            $result = $parser->parse(Source::fromString($contents));

            self::assertInstanceOf(Document::class, $result->document());

            if ($result->problems()->hasAtLeast(ProblemSeverity::Error)) {
                $oracle = file_get_contents(substr($file, 0, -\strlen('.rst')) . '.pseudoxml');
                self::assertIsString($oracle);
                self::assertStringContainsString(
                    '<system_message level="3"',
                    $oracle,
                    \sprintf(
                        'Fixture %s produced an error absent from docutils: %s',
                        basename(\dirname($file)) . '/' . basename($file),
                        implode(', ', self::problemCodes($result)),
                    ),
                );
            }
        }
    }
}
