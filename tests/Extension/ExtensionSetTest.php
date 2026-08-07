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

namespace Alto\Rst\Tests\Extension;

use Alto\Rst\Extension\DirectiveHandler;
use Alto\Rst\Extension\Extension;
use Alto\Rst\Extension\ExtensionSet;
use Alto\Rst\Extension\ExtensionStatistics;
use Alto\Rst\Extension\FixPass;
use Alto\Rst\Extension\FormatterPass;
use Alto\Rst\Extension\RoleHandler;
use Alto\Rst\Extension\StatisticsProvider;
use Alto\Rst\Extension\Symfony\ConfigurationBlockHandler;
use Alto\Rst\Extension\Symfony\ScreencastHandler;
use Alto\Rst\Extension\Symfony\SymfonyExtension;
use Alto\Rst\Lint\DocumentRule;
use Alto\Rst\Node\Document;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtensionSet::class)]
#[CoversClass(ExtensionStatistics::class)]
#[CoversClass(SymfonyExtension::class)]
final class ExtensionSetTest extends TestCase
{
    public function testCompilesCaseInsensitiveDirectiveHandlers(): void
    {
        $extension = new SymfonyExtension();
        $set = ExtensionSet::empty()->with($extension);

        self::assertSame([$extension], $set->all());
        self::assertInstanceOf(ConfigurationBlockHandler::class, $set->directiveHandler('CONFIGURATION-BLOCK'));
        self::assertInstanceOf(ScreencastHandler::class, $set->directiveHandler('SCREENCAST'));
        self::assertNull($set->directiveHandler('unknown'));
    }

    public function testLaterExtensionsReplaceEveryNamedContractCaseInsensitively(): void
    {
        $firstDirective = self::createStub(DirectiveHandler::class);
        $firstDirective->method('name')->willReturn('Panel');
        $secondDirective = self::createStub(DirectiveHandler::class);
        $secondDirective->method('name')->willReturn('PANEL');
        $firstRole = self::createStub(RoleHandler::class);
        $firstRole->method('name')->willReturn('Badge');
        $secondRole = self::createStub(RoleHandler::class);
        $secondRole->method('name')->willReturn('BADGE');
        $firstRule = self::createStub(DocumentRule::class);
        $firstRule->method('code')->willReturn('lint/replaced');
        $secondRule = self::createStub(DocumentRule::class);
        $secondRule->method('code')->willReturn('lint/replaced');
        $firstFix = self::createStub(FixPass::class);
        $firstFix->method('name')->willReturn('Cleanup');
        $secondFix = self::createStub(FixPass::class);
        $secondFix->method('name')->willReturn('CLEANUP');
        $firstFormatter = self::createStub(FormatterPass::class);
        $firstFormatter->method('name')->willReturn('Layout');
        $secondFormatter = self::createStub(FormatterPass::class);
        $secondFormatter->method('name')->willReturn('LAYOUT');
        $firstStatistics = self::createStub(StatisticsProvider::class);
        $firstStatistics->method('name')->willReturn('Metrics');
        $firstStatistics->method('collect')->willReturn(['version' => 1]);
        $secondStatistics = self::createStub(StatisticsProvider::class);
        $secondStatistics->method('name')->willReturn('METRICS');
        $secondStatistics->method('collect')->willReturn(['version' => 2]);

        $first = self::createStub(Extension::class);
        $first->method('name')->willReturn('first');
        $first->method('directiveHandlers')->willReturn([$firstDirective]);
        $first->method('roleHandlers')->willReturn([$firstRole]);
        $first->method('lintRules')->willReturn([$firstRule]);
        $first->method('fixPasses')->willReturn([$firstFix]);
        $first->method('formatterPasses')->willReturn([$firstFormatter]);
        $first->method('statisticsProviders')->willReturn([$firstStatistics]);
        $second = self::createStub(Extension::class);
        $second->method('name')->willReturn('second');
        $second->method('directiveHandlers')->willReturn([$secondDirective]);
        $second->method('roleHandlers')->willReturn([$secondRole]);
        $second->method('lintRules')->willReturn([$secondRule]);
        $second->method('fixPasses')->willReturn([$secondFix]);
        $second->method('formatterPasses')->willReturn([$secondFormatter]);
        $second->method('statisticsProviders')->willReturn([$secondStatistics]);

        $set = ExtensionSet::empty()->with($first)->with($second);

        self::assertSame([$first, $second], $set->all());
        self::assertSame($secondDirective, $set->directiveHandler('panel'));
        self::assertSame($secondRole, $set->roleHandler('badge'));
        self::assertSame([$secondRule], $set->lintRules());
        self::assertSame([$secondFix], $set->fixPasses());
        self::assertSame([$secondFormatter], $set->formatterPasses());
        self::assertSame([$secondStatistics], $set->statisticsProviders());
        self::assertNull($set->roleHandler('unknown'));

        $profile = Profile::docutils()->withExtension($first)->withExtension($second);
        $source = Source::fromString('');
        $statistics = new ExtensionStatistics()->collect(
            new Document(ByteSpan::of(0, 0)),
            $source,
            $profile,
        );

        self::assertSame(['METRICS' => ['version' => 2]], $statistics);
    }
}
