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

use Alto\Rst\Lint\ContextRule;
use Alto\Rst\Lint\DocumentRule;
use Alto\Rst\Lint\LintConfig;
use Alto\Rst\Lint\Rule\DuplicateTargetRule;
use Alto\Rst\Lint\Rule\EmptySectionRule;
use Alto\Rst\Lint\Rule\SectionLevelJumpRule;
use Alto\Rst\Lint\Rule\TransitionPlacementRule;
use Alto\Rst\Lint\SourceRule;
use Alto\Rst\Node\Document;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LintConfig::class)]
final class LintConfigTest extends TestCase
{
    private const array RECOMMENDED_CODES = [
        'lint/transition-placement',
        'lint/section-level-jump',
        'lint/duplicate-target',
        'lint/empty-section',
        'lint/forbidden-directive',
        'lint/code-block-language',
        'lint/code-block-terminal',
        'lint/version-directive-version',
        'lint/blank-line-after-directive',
        'lint/no-tab',
        'lint/indentation',
        'lint/max-line-length',
        'lint/trailing-whitespace',
        'lint/max-blank-lines',
        'lint/american-english',
        'lint/unresolved-reference',
        'lint/unused-external-link-definition',
        'lint/forbidden-link-destination',
        'lint/invalid-link-destination',
        'lint/blank-line-after-anchor',
    ];

    public function testRecommendedContainsTheBuiltInRules(): void
    {
        $codes = array_map(
            static fn(ContextRule|DocumentRule|SourceRule $rule): string => $rule->code(),
            LintConfig::recommended()->rules(),
        );

        self::assertSame(self::RECOMMENDED_CODES, $codes);
    }

    public function testRecommendedRuleTypes(): void
    {
        $rules = LintConfig::recommended()->rules();

        self::assertInstanceOf(TransitionPlacementRule::class, $rules[0]);
        self::assertInstanceOf(SectionLevelJumpRule::class, $rules[1]);
        self::assertInstanceOf(DuplicateTargetRule::class, $rules[2]);
        self::assertInstanceOf(EmptySectionRule::class, $rules[3]);
    }

    public function testRecommendedContainsBothRuleKinds(): void
    {
        $rules = LintConfig::recommended()->rules();

        self::assertNotEmpty(array_filter($rules, static fn(ContextRule|DocumentRule|SourceRule $rule): bool => $rule instanceof DocumentRule));
        self::assertNotEmpty(array_filter($rules, static fn(ContextRule|DocumentRule|SourceRule $rule): bool => $rule instanceof SourceRule));
        self::assertNotEmpty(array_filter($rules, static fn(ContextRule|DocumentRule|SourceRule $rule): bool => $rule instanceof ContextRule));
    }

    public function testWithoutRuleRemovesByCode(): void
    {
        $config = LintConfig::recommended()->withoutRule('lint/empty-section');

        $codes = array_map(
            static fn(ContextRule|DocumentRule|SourceRule $rule): string => $rule->code(),
            $config->rules(),
        );

        self::assertNotContains('lint/empty-section', $codes);
        self::assertCount(\count(self::RECOMMENDED_CODES) - 1, $config->rules());
    }

    public function testWithoutRuleWithUnknownCodeChangesNothing(): void
    {
        $config = LintConfig::recommended()->withoutRule('lint/unknown');

        self::assertCount(\count(self::RECOMMENDED_CODES), $config->rules());
    }

    public function testWithRuleAddsARule(): void
    {
        $custom = self::customRule('custom/rule');
        $config = LintConfig::recommended()->withRule($custom);

        self::assertContains($custom, $config->rules());
        self::assertCount(\count(self::RECOMMENDED_CODES) + 1, $config->rules());
    }

    public function testWithRuleReplacesARuleWithTheSameCode(): void
    {
        $replacement = self::customRule('lint/empty-section');
        $config = LintConfig::recommended()->withRule($replacement);

        self::assertCount(\count(self::RECOMMENDED_CODES), $config->rules());
        self::assertContains($replacement, $config->rules());
    }

    public function testWithSourceRuleAddsARule(): void
    {
        $custom = self::customSourceRule('custom/source-rule');
        $config = LintConfig::recommended()->withRule($custom);

        self::assertContains($custom, $config->rules());
        self::assertCount(\count(self::RECOMMENDED_CODES) + 1, $config->rules());
    }

    public function testConfigsAreImmutable(): void
    {
        $recommended = LintConfig::recommended();
        $recommended->withoutRule('lint/empty-section');
        $recommended->withRule(self::customRule('custom/rule'));

        self::assertCount(\count(self::RECOMMENDED_CODES), $recommended->rules());
    }

    private static function customRule(string $code): DocumentRule
    {
        return new readonly class ($code) implements DocumentRule {
            public function __construct(
                private string $code,
            ) {}

            public function code(): string
            {
                return $this->code;
            }

            public function check(Document $document, ProblemCollector $problems): void {}
        };
    }

    private static function customSourceRule(string $code): SourceRule
    {
        return new readonly class ($code) implements SourceRule {
            public function __construct(
                private string $code,
            ) {}

            public function code(): string
            {
                return $this->code;
            }

            public function check(Document $document, Source $source, ProblemCollector $problems): void {}
        };
    }
}
