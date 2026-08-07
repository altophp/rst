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

use Alto\Rst\Lint\Rule\DuplicateTargetRule;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\HyperlinkTarget;
use Alto\Rst\Node\Section;
use Alto\Rst\Node\Text;
use Alto\Rst\Node\Title;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DuplicateTargetRule::class)]
final class DuplicateTargetRuleTest extends TestCase
{
    public function testCode(): void
    {
        self::assertSame('lint/duplicate-target', new DuplicateTargetRule()->code());
    }

    public function testDistinctNamesAreAccepted(): void
    {
        $document = new Document(ByteSpan::of(0, 40), [
            new HyperlinkTarget(ByteSpan::of(0, 15), 'first', 'https://a.test'),
            new HyperlinkTarget(ByteSpan::of(20, 15), 'second', 'https://b.test'),
        ]);

        $problems = new ProblemCollector();
        new DuplicateTargetRule()->check($document, $problems);

        self::assertCount(0, $problems);
    }

    public function testDuplicateNameIsReportedOnTheSecondOccurrence(): void
    {
        $second = new HyperlinkTarget(ByteSpan::of(20, 15), 'name', 'https://b.test');
        $document = new Document(ByteSpan::of(0, 40), [
            new HyperlinkTarget(ByteSpan::of(0, 15), 'name', 'https://a.test'),
            $second,
        ]);

        $problems = new ProblemCollector();
        new DuplicateTargetRule()->check($document, $problems);

        $problem = $problems->report()->problems()[0];
        self::assertCount(1, $problems);
        self::assertSame('lint/duplicate-target', $problem->code);
        self::assertSame(ProblemSeverity::Warning, $problem->severity);
        self::assertSame($second->span(), $problem->span);
    }

    public function testNormalizationIsCaseInsensitive(): void
    {
        $document = new Document(ByteSpan::of(0, 40), [
            new HyperlinkTarget(ByteSpan::of(0, 15), 'My Target', 'https://a.test'),
            new HyperlinkTarget(ByteSpan::of(20, 15), 'my target', 'https://b.test'),
        ]);

        $problems = new ProblemCollector();
        new DuplicateTargetRule()->check($document, $problems);

        self::assertCount(1, $problems);
    }

    public function testNormalizationCollapsesInternalWhitespaceRuns(): void
    {
        $document = new Document(ByteSpan::of(0, 40), [
            new HyperlinkTarget(ByteSpan::of(0, 15), "my \t  target", 'https://a.test'),
            new HyperlinkTarget(ByteSpan::of(20, 15), 'my target', 'https://b.test'),
        ]);

        $problems = new ProblemCollector();
        new DuplicateTargetRule()->check($document, $problems);

        self::assertCount(1, $problems);
    }

    public function testAnonymousTargetsAreIgnored(): void
    {
        $document = new Document(ByteSpan::of(0, 40), [
            new HyperlinkTarget(ByteSpan::of(0, 15), '', 'https://a.test', true),
            new HyperlinkTarget(ByteSpan::of(20, 15), '', 'https://b.test', true),
        ]);

        $problems = new ProblemCollector();
        new DuplicateTargetRule()->check($document, $problems);

        self::assertCount(0, $problems);
    }

    public function testTargetsNestedInSectionsAreSeenDocumentWide(): void
    {
        $duplicate = new HyperlinkTarget(ByteSpan::of(40, 15), 'name', 'https://b.test');
        $titleSpan = ByteSpan::of(20, 5);
        $section = new Section(
            ByteSpan::of(20, 40),
            1,
            new Title($titleSpan, new Text($titleSpan, 'Title')),
            [$duplicate],
            '=',
            false,
        );
        $document = new Document(ByteSpan::of(0, 60), [
            new HyperlinkTarget(ByteSpan::of(0, 15), 'name', 'https://a.test'),
            $section,
        ]);

        $problems = new ProblemCollector();
        new DuplicateTargetRule()->check($document, $problems);

        $problem = $problems->report()->problems()[0];
        self::assertCount(1, $problems);
        self::assertSame($duplicate->span(), $problem->span);
    }

    public function testEveryOccurrenceAfterTheFirstIsReported(): void
    {
        $document = new Document(ByteSpan::of(0, 60), [
            new HyperlinkTarget(ByteSpan::of(0, 15), 'name', 'https://a.test'),
            new HyperlinkTarget(ByteSpan::of(20, 15), 'name', 'https://b.test'),
            new HyperlinkTarget(ByteSpan::of(40, 15), 'name', 'https://c.test'),
        ]);

        $problems = new ProblemCollector();
        new DuplicateTargetRule()->check($document, $problems);

        self::assertCount(2, $problems);
    }
}
