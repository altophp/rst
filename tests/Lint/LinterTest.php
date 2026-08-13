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

namespace Alto\Rst\Tests\Lint;

use Alto\Rst\Lint\DocumentRule;
use Alto\Rst\Lint\LintConfig;
use Alto\Rst\Lint\Linter;
use Alto\Rst\Lint\SourceRule;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\HyperlinkTarget;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Section;
use Alto\Rst\Node\Text;
use Alto\Rst\Node\Title;
use Alto\Rst\Node\Transition;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemReport;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Linter::class)]
final class LinterTest extends TestCase
{
    public function testCleanDocumentYieldsAnEmptyReport(): void
    {
        $paragraphSpan = ByteSpan::of(12, 5);
        $document = new Document(ByteSpan::of(0, 20), [
            self::section(0, [new Paragraph($paragraphSpan, new Text($paragraphSpan, 'First'))]),
        ]);

        $report = new Linter()->lint($document, LintConfig::recommended());

        self::assertInstanceOf(ProblemReport::class, $report);
        self::assertFalse($report->hasProblems());
    }

    public function testProblemsFromDifferentRulesMergeInDocumentOrder(): void
    {
        // Transition at offset 0 (transition-placement), empty section at
        // offset 6 (empty-section), duplicate targets at 25 and 45
        // (duplicate-target): three rules, interleaved document positions.
        $document = new Document(ByteSpan::of(0, 60), [
            new Transition(ByteSpan::of(0, 4)),
            self::section(6),
            new HyperlinkTarget(ByteSpan::of(25, 15), 'name', 'https://a.test'),
            new HyperlinkTarget(ByteSpan::of(45, 15), 'name', 'https://b.test'),
        ]);

        $report = new Linter()->lint($document, LintConfig::recommended());

        $codes = array_map(static fn(Problem $problem): string => $problem->code, $report->problems());
        $starts = array_map(static fn(Problem $problem): ?int => $problem->span?->start, $report->problems());

        self::assertSame(['lint/transition-placement', 'lint/empty-section', 'lint/duplicate-target'], $codes);
        self::assertSame([0, 6, 45], $starts);
    }

    public function testDisabledRuleReportsNothing(): void
    {
        $document = new Document(ByteSpan::of(0, 20), [self::section(0)]);

        $config = LintConfig::recommended()->withoutRule('lint/empty-section');
        $report = new Linter()->lint($document, $config);

        self::assertFalse($report->hasProblems());
    }

    public function testAddedRuleRuns(): void
    {
        $document = new Document(ByteSpan::of(0, 0));

        $custom = new readonly class implements DocumentRule {
            public function code(): string
            {
                return 'custom/always';
            }

            public function check(Document $document, ProblemCollector $problems): void
            {
                $problems->add(new Problem(ProblemSeverity::Info, $this->code(), 'Always fires.', $document->span()));
            }
        };

        $report = new Linter()->lint($document, LintConfig::recommended()->withRule($custom));

        self::assertCount(1, $report);
        self::assertSame('custom/always', $report->problems()[0]->code);
    }

    public function testSourceRulesRunWhenTheSourceIsProvided(): void
    {
        $document = new Document(ByteSpan::of(0, 0));
        $config = LintConfig::recommended()->withRule(self::alwaysFiringSourceRule());

        $report = new Linter()->lint($document, $config, Source::fromString(''));

        $codes = array_map(static fn(Problem $problem): string => $problem->code, $report->problems());

        self::assertContains('custom/source-always', $codes);
    }

    public function testSourceRulesAreSkippedWithoutASource(): void
    {
        $document = new Document(ByteSpan::of(0, 0));
        $config = LintConfig::recommended()->withRule(self::alwaysFiringSourceRule());

        $report = new Linter()->lint($document, $config);

        self::assertFalse($report->hasProblems());
    }

    public function testDocumentAndSourceProblemsMergeInDocumentOrder(): void
    {
        // A transition at offset 0 (transition-placement, document rule)
        // and trailing whitespace on the last line (source rule).
        $rst = "----\n\nParagraph.  \n";
        $document = new Document(ByteSpan::of(0, \strlen($rst)), [
            new Transition(ByteSpan::of(0, 4)),
        ]);

        $report = new Linter()->lint($document, LintConfig::recommended(), Source::fromString($rst));

        $codes = array_map(static fn(Problem $problem): string => $problem->code, $report->problems());

        self::assertSame(['lint/transition-placement', 'lint/trailing-whitespace'], $codes);
    }

    private static function alwaysFiringSourceRule(): SourceRule
    {
        return new readonly class implements SourceRule {
            public function code(): string
            {
                return 'custom/source-always';
            }

            public function check(Document $document, Source $source, ProblemCollector $problems): void
            {
                $problems->add(new Problem(ProblemSeverity::Info, $this->code(), 'Always fires.', $document->span()));
            }
        };
    }

    /**
     * @param list<Node> $body
     */
    private static function section(int $start, array $body = []): Section
    {
        $titleSpan = ByteSpan::of($start, 5);
        $title = new Title($titleSpan, new Text($titleSpan, 'Title'));

        return new Section(ByteSpan::of($start, 15), 1, $title, $body, '=', false);
    }
}
