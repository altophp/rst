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

namespace Alto\Rst\Tests\Parser\Inline;

use Alto\Rst\Node\Inline\InlineText;
use Alto\Rst\Parser\InlineParser;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemSeverity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(InlineParser::class)]
final class InlineParserProblemTest extends InlineParserTestCase
{
    #[DataProvider('problemCases')]
    public function testMalformedMarkupIsReportedAndRecovered(string $text, string $code, ProblemSeverity $severity): void
    {
        $problems = self::parseProblems($text);

        self::assertCount(1, $problems);
        self::assertSame($code, $problems[0]->code);
        self::assertSame($severity, $problems[0]->severity);
        self::assertNotSame([], self::parseInline($text));
    }

    /**
     * @return iterable<string, array{string, string, ProblemSeverity}>
     */
    public static function problemCases(): iterable
    {
        yield 'unmatched emphasis' => ['a *b c', 'inline/unmatched-start-string', ProblemSeverity::Warning];
        yield 'unmatched strong' => ['a **b c', 'inline/unmatched-start-string', ProblemSeverity::Warning];
        yield 'unmatched backquote' => ['a `b c', 'inline/unmatched-start-string', ProblemSeverity::Warning];
        yield 'unmatched substitution' => ['a |b c', 'inline/unmatched-start-string', ProblemSeverity::Warning];
        yield 'unmatched inline target' => ['a _`b c', 'inline/unmatched-start-string', ProblemSeverity::Warning];
        yield 'unclosed literal' => ['a ``b c', 'inline/unclosed-literal', ProblemSeverity::Warning];
        yield 'role prefix with a reference suffix' => ['a :role:`b`_ c', 'inline/malformed-role', ProblemSeverity::Warning];
        yield 'role prefix with a role suffix' => ['a :one:`b`:two: c', 'inline/malformed-role', ProblemSeverity::Warning];
        yield 'empty footnote label' => ['a []_ c', 'inline/empty-reference', ProblemSeverity::Warning];
        yield 'reference without text' => ['a `< >`_ c', 'inline/empty-reference', ProblemSeverity::Warning];
        yield 'nested markup' => ['a *b ``c`` d* e', 'inline/nested-markup', ProblemSeverity::Info];
    }

    public function testProblemSpansUseTheBaseOffset(): void
    {
        $span = self::parseProblems('Unclosed *emphasis here.', 500)[0]->span;

        self::assertNotNull($span);
        self::assertSame([509, 510], [$span->start, $span->end()]);
    }

    public function testUnclosedLiteralSpanCoversTheStartString(): void
    {
        $span = self::parseProblems('Unclosed ``literal here.')[0]->span;

        self::assertNotNull($span);
        self::assertSame([9, 11], [$span->start, $span->end()]);
    }

    public function testMalformedRoleSpanCoversTheWholeConstruct(): void
    {
        $span = self::parseProblems('Bad :role:`text`_ suffix.')[0]->span;

        self::assertNotNull($span);
        self::assertSame([4, 17], [$span->start, $span->end()]);
    }

    public function testEveryProblemCarriesACodeAndAMessage(): void
    {
        $problems = self::parseProblems('a *b `c ``d |e _`f :role:`g`_');

        self::assertNotSame([], $problems);

        foreach ($problems as $problem) {
            self::assertNotSame('', $problem->message);
            self::assertStringStartsWith('inline/', $problem->code);
        }
    }

    #[DataProvider('degradationCases')]
    public function testMalformedMarkupKeepsScanningAfterTheOffendingRun(string $text, string $expected): void
    {
        self::assertSame($expected, self::outline($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function degradationCases(): iterable
    {
        yield 'text after an unmatched start-string' => ['a *b and ``c`` d', 'text(a *b and )literal(c)text( d)'];
        yield 'text after an unclosed literal' => ['a ``b and *c* d', 'text(a ``b and )em(text(c))text( d)'];
        yield 'reference after a malformed role' => ['a :role:`b`_ c', 'text(a :role:)reference(b)text( c)'];
        yield 'interpreted text after a malformed role' => ['a :one:`b`:two: c', 'text(a :one:)interpreted(two,b,suffix)text( c)'];
    }

    #[DataProvider('adversarialCases')]
    public function testScanningNeverThrowsAndKeepsSpansInsideTheSlice(string $input): void
    {
        $nodes = self::parseInline($input, 7);
        $cursor = 7;

        foreach ($nodes as $node) {
            self::assertGreaterThanOrEqual($cursor, $node->span()->start);
            $cursor = $node->span()->end();
        }

        self::assertLessThanOrEqual(7 + \strlen($input), $cursor);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function adversarialCases(): iterable
    {
        foreach ([
            '*', '**', '***', '****', '`', '``', '```', '````', '|', '||', '[', '[]', '[]_', '_`', ':', '::',
            '*`|_[', '\\', '\\\\', '\\*', ' `` ', '`_', '`__', '`<>`_', '|_|', '*_*', '_*_', ':a:', ':a:`',
            '_`a', 'a_', '__', 'https://', 'mailto:', '<>', '*a`b|c', "*\n*", '``a`', '|a', '[#]', '[*]',
        ] as $input) {
            yield $input => [$input];
        }
    }

    public function testTextRunsAroundAProblemStayContiguous(): void
    {
        $nodes = self::parseInline('a *b c');
        $text = self::nodeAt($nodes, 0, InlineText::class);

        self::assertCount(1, $nodes);
        self::assertSame('a *b c', $text->text);
        self::assertSame([0, 6], self::bounds($text));
    }

    public function testProblemsAccumulateInSourceOrder(): void
    {
        $codes = array_map(
            static fn(Problem $problem): string => $problem->code,
            self::parseProblems('a ``b c *d e'),
        );

        self::assertSame(['inline/unclosed-literal', 'inline/unmatched-start-string'], $codes);
    }

    public function testEveryNodeCarriesASpanOverTheOriginalBytes(): void
    {
        $source = 'See `page <https://example.com/>`_ and |name| and [1]_ and *a*.';

        $nodes = self::parseInline($source, 12);

        self::assertCount(9, $nodes);

        foreach ($nodes as $node) {
            self::assertGreaterThanOrEqual(12, $node->span()->start);
            self::assertLessThanOrEqual(12 + \strlen($source), $node->span()->end());
        }
    }
}
