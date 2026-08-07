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

namespace Alto\Rst\Tests\Lint\Rule;

use Alto\Rst\Lint\Rule\EmptySectionRule;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Section;
use Alto\Rst\Node\Text;
use Alto\Rst\Node\Title;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmptySectionRule::class)]
final class EmptySectionRuleTest extends TestCase
{
    public function testCode(): void
    {
        self::assertSame('lint/empty-section', new EmptySectionRule()->code());
    }

    public function testSectionWithBodyIsAccepted(): void
    {
        $paragraphSpan = ByteSpan::of(12, 5);
        $document = new Document(ByteSpan::of(0, 20), [
            self::section(0, [new Paragraph($paragraphSpan, new Text($paragraphSpan, 'First'))]),
        ]);

        $problems = new ProblemCollector();
        new EmptySectionRule()->check($document, $problems);

        self::assertCount(0, $problems);
    }

    public function testTitleOnlySectionIsReportedAsInfo(): void
    {
        $empty = self::section(0);
        $document = new Document(ByteSpan::of(0, 20), [$empty]);

        $problems = new ProblemCollector();
        new EmptySectionRule()->check($document, $problems);

        $problem = $problems->report()->problems()[0];
        self::assertCount(1, $problems);
        self::assertSame('lint/empty-section', $problem->code);
        self::assertSame(ProblemSeverity::Info, $problem->severity);
        self::assertSame($empty->span(), $problem->span);
    }

    public function testNestedEmptySectionIsReported(): void
    {
        $empty = self::section(20);
        $document = new Document(ByteSpan::of(0, 40), [
            self::section(0, [$empty]),
        ]);

        $problems = new ProblemCollector();
        new EmptySectionRule()->check($document, $problems);

        $problem = $problems->report()->problems()[0];
        self::assertCount(1, $problems);
        self::assertSame($empty->span(), $problem->span);
    }

    public function testSectionContainingOnlyAnEmptySubsectionIsNotItselfEmpty(): void
    {
        $document = new Document(ByteSpan::of(0, 40), [
            self::section(0, [self::section(20)]),
        ]);

        $problems = new ProblemCollector();
        new EmptySectionRule()->check($document, $problems);

        self::assertCount(1, $problems);
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
