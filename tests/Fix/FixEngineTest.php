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

namespace Alto\Rst\Tests\Fix;

use Alto\Rst\Fix\FixEngine;
use Alto\Rst\Fix\FixOptions;
use Alto\Rst\Fix\FixResult;
use Alto\Rst\Fix\ProtectedSpanIndex;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Operation\SourcePatch;
use Alto\Rst\Profile\Profile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FixEngine::class)]
#[CoversClass(FixResult::class)]
#[CoversClass(ProtectedSpanIndex::class)]
final class FixEngineTest extends TestCase
{
    public function testRemovesTrailingWhitespaceAndExcessBlankLines(): void
    {
        $source = "First.  \n \n\t\n\nSecond.\t\n";

        $result = new FixEngine()->fix($source, new FixOptions(maxBlankLines: 2));

        self::assertSame("First.\n\n\nSecond.\n", $result->bytes);
        self::assertCount(5, $result->patches);
        self::assertSame(0, $result->skippedProtectedEdits);
        self::assertTrue($result->changed());
        self::assertCount(2, $result->parseResult->document()->children());
        self::assertContainsOnlyInstancesOf(Paragraph::class, $result->parseResult->document()->children());
        $this->assertBytesOutsidePatchesUnchanged($source, $result->bytes, $result->patches);
    }

    public function testPreservesBomAndEveryExistingLineEndingStyle(): void
    {
        $source = "\xEF\xBB\xBFFirst.  \r\nSecond.\t\rThird.  \n";

        $result = new FixEngine()->fix($source);

        self::assertSame("\xEF\xBB\xBFFirst.\r\nSecond.\rThird.\n", $result->bytes);
        self::assertSame("\xEF\xBB\xBF", substr($result->bytes, 0, 3));
        self::assertSame(3, substr_count($result->bytes, "\r") + substr_count($result->bytes, "\n") - substr_count($result->bytes, "\r\n"));
        $this->assertBytesOutsidePatchesUnchanged($source, $result->bytes, $result->patches);
    }

    public function testLeavesLiteralBlockWhitespaceUntouched(): void
    {
        $source = "Code::\n\n    first  \n\n\n\n    second\t\n\nAfter.  \n";

        $result = new FixEngine()->fix($source, new FixOptions(maxBlankLines: 2));

        self::assertSame("Code::\n\n    first  \n\n\n\n    second\t\n\nAfter.\n", $result->bytes);
        self::assertSame(3, $result->skippedProtectedEdits);
        self::assertCount(1, $result->patches);
        self::assertSame('  ', substr($source, $result->patches[0]->span->start, $result->patches[0]->span->length));
    }

    public function testLeavesDirectiveCommentAndTableWhitespaceUntouched(): void
    {
        $source = <<<'RST'
            .. code-block:: php

               echo 1;  



               echo 2;	

            .. raw comment  
               body  

            =====  =====
            one    two  
            =====  =====

            Outside.  
            RST;

        $result = new FixEngine()->fix($source, new FixOptions(maxBlankLines: 2));

        self::assertStringContainsString("   echo 1;  \n\n\n\n   echo 2;\t", $result->bytes);
        self::assertStringContainsString(".. raw comment  \n   body  ", $result->bytes);
        self::assertStringContainsString('one    two  ', $result->bytes);
        self::assertStringEndsWith('Outside.', $result->bytes);
        self::assertGreaterThanOrEqual(4, $result->skippedProtectedEdits);
    }

    public function testSkipsAWholeBlankRunWhenAnyLineIsProtected(): void
    {
        $source = "Code::\n\n    one\n\n\n\n    two\n";

        $result = new FixEngine()->fix(
            $source,
            new FixOptions(removeTrailingWhitespace: false, maxBlankLines: 1),
        );

        self::assertSame($source, $result->bytes);
        self::assertSame([], $result->patches);
        self::assertSame(2, $result->skippedProtectedEdits);
    }

    public function testCanDisableEitherFixPass(): void
    {
        $source = "First.  \n\n\nSecond.  \n";
        $engine = new FixEngine();

        $trailingOnly = $engine->fix($source, new FixOptions(maxBlankLines: null));
        $blankLinesOnly = $engine->fix($source, new FixOptions(removeTrailingWhitespace: false, maxBlankLines: 1));

        self::assertSame("First.\n\n\nSecond.\n", $trailingOnly->bytes);
        self::assertSame("First.  \n\nSecond.  \n", $blankLinesOnly->bytes);
    }

    public function testFixesAreIdempotentAndUseTheSelectedProfile(): void
    {
        $source = "Paragraph.  \n\n\n\nNext.\t\n";
        $engine = new FixEngine();
        $first = $engine->fix($source, profile: Profile::symfony());
        $second = $engine->fix($first->bytes, profile: Profile::symfony());

        self::assertSame("Paragraph.\n\n\nNext.\n", $first->bytes);
        self::assertSame($first->bytes, $second->bytes);
        self::assertSame([], $second->patches);
        self::assertFalse($second->changed());
    }

    public function testAddsBlankLinesAfterInternalAnchorsAndBeforeDirectiveBodies(): void
    {
        $source = ".. _target:\r\n"
            . "Title\r\n"
            . "=====\r\n\r\n"
            . ".. note::\r\n"
            . "    Body.\r\n";

        $result = new FixEngine()->fix($source);

        self::assertSame(
            ".. _target:\r\n\r\n"
            . "Title\r\n"
            . "=====\r\n\r\n"
            . ".. note::\r\n\r\n"
            . "    Body.\r\n",
            $result->bytes,
        );
        self::assertCount(2, $result->patches);
        self::assertFalse($result->parseResult->problems()->hasProblems());
    }

    public function testBlankLinePassesCanBeDisabledAndIgnoreExternalTargets(): void
    {
        $source = ".. _external: https://example.test/\n"
            . "Paragraph.\n\n"
            . ".. note::\n"
            . "    Body.\n";

        $result = new FixEngine()->fix(
            $source,
            new FixOptions(
                blankLineAfterAnchor: false,
                blankLineBeforeDirectiveBody: false,
            ),
        );

        self::assertSame($source, $result->bytes);
        self::assertSame([], $result->patches);
    }

    public function testDefaultRoleLiteralNormalizationIsExplicitAndLocal(): void
    {
        $source = "Use `cache` and :ref:`target` with ``literal``.\n\n"
            . ".. _target:\n\n"
            . "Target\n======\n";
        $engine = new FixEngine();

        $default = $engine->fix($source);
        $normalized = $engine->fix(
            $source,
            new FixOptions(normalizeDefaultRoleAsLiteral: true),
        );

        self::assertSame($source, $default->bytes);
        self::assertSame(
            "Use ``cache`` and :ref:`target` with ``literal``.\n\n"
            . ".. _target:\n\n"
            . "Target\n======\n",
            $normalized->bytes,
        );
        self::assertCount(1, $normalized->patches);
        self::assertSame('`cache`', substr(
            $source,
            $normalized->patches[0]->span->start,
            $normalized->patches[0]->span->length,
        ));
    }

    public function testDefaultRoleLiteralNormalizationIsIdempotentAndSkipsProtectedContent(): void
    {
        $source = "Outside `value`.\n\n"
            . ".. note::\n\n"
            . "    Inside `value`.\n\n"
            . "Code::\n\n"
            . "    `value`\n";
        $options = new FixOptions(normalizeDefaultRoleAsLiteral: true);
        $engine = new FixEngine();

        $first = $engine->fix($source, $options);
        $second = $engine->fix($first->bytes, $options);

        self::assertStringStartsWith('Outside ``value``.', $first->bytes);
        self::assertStringContainsString('Inside `value`.', $first->bytes);
        self::assertStringEndsWith("    `value`\n", $first->bytes);
        self::assertSame($first->bytes, $second->bytes);
        self::assertSame([], $second->patches);
    }

    public function testLeavesARecoveredDocumentEntirelyUntouched(): void
    {
        $source = "Title\n=  \n\nParagraph.  \n";

        $result = new FixEngine()->fix(
            $source,
            new FixOptions(normalizeDefaultRoleAsLiteral: true),
        );

        self::assertSame($source, $result->bytes);
        self::assertSame(2, $result->skippedProtectedEdits);
        self::assertSame([], $result->patches);
        self::assertTrue($result->parseResult->problems()->hasProblems());
    }

    public function testLeavesStructuralWhitespaceInSupportedGridTableUntouched(): void
    {
        $source = "+---+---+\n| a | b |  \n+---+---+  \n";

        $result = new FixEngine()->fix($source);

        self::assertSame($source, $result->bytes);
        self::assertSame([], $result->patches);
        self::assertSame(2, $result->skippedProtectedEdits);
        self::assertFalse($result->parseResult->problems()->hasProblems());
    }

    /**
     * @param list<SourcePatch> $patches
     */
    private function assertBytesOutsidePatchesUnchanged(string $before, string $after, array $patches): void
    {
        $beforeCursor = 0;
        $afterCursor = 0;

        foreach ($patches as $patch) {
            $unchangedLength = $patch->span->start - $beforeCursor;

            self::assertSame(
                substr($before, $beforeCursor, $unchangedLength),
                substr($after, $afterCursor, $unchangedLength),
            );

            $beforeCursor = $patch->span->end();
            $afterCursor += $unchangedLength + \strlen($patch->replacement);
        }

        self::assertSame(substr($before, $beforeCursor), substr($after, $afterCursor));
    }
}
