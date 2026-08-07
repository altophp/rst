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

use Alto\Rst\Lint\Rule\SectionLevelJumpRule;
use Alto\Rst\Node\BulletList;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\ListItem;
use Alto\Rst\Node\Node;
use Alto\Rst\Node\Section;
use Alto\Rst\Node\Text;
use Alto\Rst\Node\Title;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SectionLevelJumpRule::class)]
final class SectionLevelJumpRuleTest extends TestCase
{
    public function testCode(): void
    {
        self::assertSame('lint/section-level-jump', new SectionLevelJumpRule()->code());
    }

    public function testConsecutiveLevelsAreAccepted(): void
    {
        $document = new Document(ByteSpan::of(0, 60), [
            self::section(0, 1, [
                self::section(20, 2, [
                    self::section(40, 3),
                ]),
            ]),
        ]);

        $problems = new ProblemCollector();
        new SectionLevelJumpRule()->check($document, $problems);

        self::assertCount(0, $problems);
    }

    public function testTopLevelSectionDeeperThanOneIsReported(): void
    {
        $jumped = self::section(0, 2);
        $document = new Document(ByteSpan::of(0, 20), [$jumped]);

        $problems = new ProblemCollector();
        new SectionLevelJumpRule()->check($document, $problems);

        $problem = $problems->report()->problems()[0];
        self::assertCount(1, $problems);
        self::assertSame('lint/section-level-jump', $problem->code);
        self::assertSame(ProblemSeverity::Warning, $problem->severity);
        self::assertSame($jumped->span(), $problem->span);
    }

    public function testNestedLevelJumpIsReported(): void
    {
        $jumped = self::section(20, 3);
        $document = new Document(ByteSpan::of(0, 60), [
            self::section(0, 1, [$jumped]),
        ]);

        $problems = new ProblemCollector();
        new SectionLevelJumpRule()->check($document, $problems);

        $problem = $problems->report()->problems()[0];
        self::assertCount(1, $problems);
        self::assertSame($jumped->span(), $problem->span);
    }

    public function testChildrenOfAJumpedSectionAreCheckedAgainstItsOwnLevel(): void
    {
        $document = new Document(ByteSpan::of(0, 60), [
            self::section(0, 3, [
                self::section(20, 4),
            ]),
        ]);

        $problems = new ProblemCollector();
        new SectionLevelJumpRule()->check($document, $problems);

        self::assertCount(1, $problems);
    }

    public function testSectionsInsideContainerNodesAreCheckedAgainstTheOuterContext(): void
    {
        $jumped = self::section(10, 2);
        $item = new ListItem(ByteSpan::of(10, 20), [$jumped]);
        $document = new Document(ByteSpan::of(0, 40), [
            new BulletList(ByteSpan::of(10, 20), '-', [$item]),
        ]);

        $problems = new ProblemCollector();
        new SectionLevelJumpRule()->check($document, $problems);

        $problem = $problems->report()->problems()[0];
        self::assertCount(1, $problems);
        self::assertSame('Section level jumps from 0 to 2.', $problem->message);
        self::assertSame($jumped->span(), $problem->span);
    }

    public function testSiblingAtTheSameLevelIsAccepted(): void
    {
        $document = new Document(ByteSpan::of(0, 60), [
            self::section(0, 1),
            self::section(20, 1),
        ]);

        $problems = new ProblemCollector();
        new SectionLevelJumpRule()->check($document, $problems);

        self::assertCount(0, $problems);
    }

    /**
     * @param list<Node> $body
     */
    private static function section(int $start, int $level, array $body = []): Section
    {
        $titleSpan = ByteSpan::of($start, 5);
        $title = new Title($titleSpan, new Text($titleSpan, 'Title'));

        return new Section(ByteSpan::of($start, 15), $level, $title, $body, '=', false);
    }
}
