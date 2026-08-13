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

use Alto\Rst\Convert\ConversionIssue;
use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\ConversionReport;
use Alto\Rst\Convert\ConversionResult;
use Alto\Rst\Convert\RstToMarkdown;
use Alto\Rst\Convert\Writer\MarkdownWriter;
use Alto\Rst\Extension\AbstractExtension;
use Alto\Rst\Extension\ExtensionStatistics;
use Alto\Rst\Extension\FixPass;
use Alto\Rst\Extension\FormatterPass;
use Alto\Rst\Extension\RoleHandler;
use Alto\Rst\Extension\StatisticsProvider;
use Alto\Rst\Fix\FixEngine;
use Alto\Rst\Format\FormatOptions;
use Alto\Rst\Format\Formatter;
use Alto\Rst\Lint\DocumentRule;
use Alto\Rst\Lint\LintConfig;
use Alto\Rst\Lint\Linter;
use Alto\Rst\Lint\SourceRule;
use Alto\Rst\Node\Document;
use Alto\Rst\Node\Inline\InterpretedText;
use Alto\Rst\Operation\SourcePatch;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Profile\RoleSpec;
use Alto\Rst\Render\HtmlPolicy;
use Alto\Rst\Render\HtmlRenderer;
use Alto\Rst\Render\RenderOptions;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractExtension::class)]
#[CoversClass(ExtensionStatistics::class)]
#[CoversClass(FixEngine::class)]
#[CoversClass(Formatter::class)]
#[CoversClass(Linter::class)]
#[CoversClass(HtmlRenderer::class)]
#[CoversClass(MarkdownWriter::class)]
#[CoversClass(RstToMarkdown::class)]
final class ExtensionPipelineTest extends TestCase
{
    public function testAbstractExtensionDefaultsToNoCapabilities(): void
    {
        $extension = new readonly class extends AbstractExtension {
            public function name(): string
            {
                return 'empty';
            }
        };

        self::assertSame([], $extension->directives());
        self::assertSame([], $extension->roles());
        self::assertSame([], $extension->directiveHandlers());
        self::assertSame([], $extension->roleHandlers());
        self::assertSame([], $extension->lintRules());
        self::assertSame([], $extension->fixPasses());
        self::assertSame([], $extension->formatterPasses());
        self::assertSame([], $extension->statisticsProviders());
    }

    public function testRoleHandlerResolvesAnAliasAndMergesItsConversionIssues(): void
    {
        $handler = new class implements RoleHandler {
            public function name(): string
            {
                return 'badge';
            }

            public function renderHtml(
                InterpretedText $role,
                Source $source,
                Profile $profile,
                HtmlPolicy $htmlPolicy,
            ): string {
                return '<mark>' . htmlspecialchars($role->text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</mark>';
            }

            public function convertToMarkdown(
                InterpretedText $role,
                Source $source,
                Profile $profile,
                ConversionOptions $options,
            ): ConversionResult {
                return new ConversionResult(
                    '**' . $role->text . '**',
                    new ConversionReport([
                        ConversionIssue::lossy('role:badge', 'Badge styling was flattened.', $role->span()),
                    ]),
                );
            }
        };
        $extension = new readonly class ($handler) extends AbstractExtension {
            public function __construct(
                private RoleHandler $handler,
            ) {}

            public function name(): string
            {
                return 'role-alias';
            }

            public function roles(): array
            {
                return [new RoleSpec('badge', ['mark'])];
            }

            public function roleHandlers(): array
            {
                return [$this->handler];
            }
        };
        $profile = Profile::docutils()->withExtension($extension);
        $source = Source::fromString(":mark:`hello`\n");
        $parsed = new BlockParser()->parse($source, $profile);

        self::assertSame(
            "<p><mark>hello</mark></p>\n",
            new HtmlRenderer()->render(
                $parsed->document(),
                $source,
                new RenderOptions(profile: $profile),
                $parsed->references(),
            ),
        );

        $conversion = new RstToMarkdown()->convert($parsed->document(), $source, $profile);

        self::assertSame("**hello**\n", $conversion->output);
        self::assertSame(['role:badge' => 1], $conversion->report->countsByConstruct());
    }

    public function testExtensionLintRuleOverridesAConfiguredRuleWithTheSameCode(): void
    {
        $rule = new readonly class implements DocumentRule {
            public function code(): string
            {
                return 'lint/trailing-whitespace';
            }

            public function check(Document $document, ProblemCollector $problems): void
            {
                $problems->add(new Problem(
                    ProblemSeverity::Info,
                    $this->code(),
                    'Extension override ran.',
                    $document->span(),
                ));
            }
        };
        $extension = new readonly class ($rule) extends AbstractExtension {
            public function __construct(
                private DocumentRule $rule,
            ) {}

            public function name(): string
            {
                return 'lint-override';
            }

            public function lintRules(): array
            {
                return [$this->rule];
            }
        };
        $profile = Profile::docutils()->withExtension($extension);
        $source = Source::fromString("Text.  \n");
        $parsed = new BlockParser()->parse($source, $profile);
        $report = new Linter()->lint(
            $parsed->document(),
            LintConfig::recommended(),
            $source,
            $parsed->references(),
            $profile,
        );
        $matching = array_values(array_filter(
            $report->problems(),
            static fn(Problem $problem): bool => 'lint/trailing-whitespace' === $problem->code,
        ));

        self::assertCount(1, $matching);
        self::assertSame('Extension override ran.', $matching[0]->message);
    }

    public function testExtensionFixPassContributesSourcePatches(): void
    {
        $pass = new readonly class implements FixPass {
            public function name(): string
            {
                return 'spelling';
            }

            public function patches(Document $document, Source $source, Profile $profile): array
            {
                return [new SourcePatch(ByteSpan::of(0, 3), 'the')];
            }
        };
        $extension = new readonly class ($pass) extends AbstractExtension {
            public function __construct(
                private FixPass $pass,
            ) {}

            public function name(): string
            {
                return 'fix-pass';
            }

            public function fixPasses(): array
            {
                return [$this->pass];
            }
        };

        $result = new FixEngine()->fix(
            "teh\n",
            new \Alto\Rst\Fix\FixOptions(removeTrailingWhitespace: false, maxBlankLines: null),
            Profile::docutils()->withExtension($extension),
        );

        self::assertSame("the\n", $result->bytes);
        self::assertCount(1, $result->patches);
    }

    public function testFormatterRunsEmptyAndSafePassesAndRejectsAStructuralPass(): void
    {
        $empty = new readonly class implements FormatterPass {
            public function name(): string
            {
                return 'empty';
            }

            public function patches(Document $document, Source $source, Profile $profile): array
            {
                return [];
            }
        };
        $safe = new readonly class implements FormatterPass {
            public function name(): string
            {
                return 'safe';
            }

            public function patches(Document $document, Source $source, Profile $profile): array
            {
                return [new SourcePatch(ByteSpan::of(0, 1), '-')];
            }
        };
        $structural = new readonly class implements FormatterPass {
            public function name(): string
            {
                return 'structural';
            }

            public function patches(Document $document, Source $source, Profile $profile): array
            {
                return [new SourcePatch(ByteSpan::of(8, 9), '* other')];
            }
        };
        $extension = new readonly class ($empty, $safe, $structural) extends AbstractExtension {
            public function __construct(
                private FormatterPass $empty,
                private FormatterPass $safe,
                private FormatterPass $structural,
            ) {}

            public function name(): string
            {
                return 'formatter-passes';
            }

            public function formatterPasses(): array
            {
                return [$this->empty, $this->safe, $this->structural];
            }
        };

        $result = new Formatter()->format(
            "* item\n\nParagraph\n",
            new FormatOptions(normalizeSectionAdornments: false, bulletMarker: null),
            Profile::docutils()->withExtension($extension),
        );

        self::assertSame("- item\n\nParagraph\n", $result->bytes);
        self::assertCount(1, $result->patches);
        self::assertSame(1, $result->skippedExtensionPasses);
    }

    public function testExecutesRoleLintFixFormatAndStatisticsContracts(): void
    {
        $profile = $this->profile();
        $source = Source::fromString(":badge:`hello`\n");
        $parsed = new BlockParser()->parse($source, $profile);

        self::assertSame(
            "<p><mark>hello</mark></p>\n",
            new HtmlRenderer()->render(
                $parsed->document(),
                $source,
                new RenderOptions(profile: $profile),
                $parsed->references(),
            ),
        );
        self::assertSame(
            "**hello**\n",
            new RstToMarkdown()->convert($parsed->document(), $source, $profile)->output,
        );

        $lint = new Linter()->lint(
            $parsed->document(),
            LintConfig::recommended(),
            $source,
            $parsed->references(),
            $profile,
        );
        self::assertContains('lint/test-extension', array_map(
            static fn(Problem $problem): string => $problem->code,
            $lint->problems(),
        ));

        self::assertSame("the\n", new FixEngine()->fix("teh\n", profile: $profile)->bytes);

        $format = new Formatter()->format(
            "* item\n",
            new FormatOptions(normalizeSectionAdornments: false, bulletMarker: null),
            $profile,
        );
        self::assertSame("- item\n", $format->bytes);
        self::assertSame(0, $format->skippedExtensionPasses);

        $statistics = new ExtensionStatistics()->collect($parsed->document(), $source, $profile);
        self::assertSame(['test' => ['bytes' => 15]], $statistics);
    }

    public function testFormatterRejectsAnExtensionPassThatChangesTheTree(): void
    {
        $result = new Formatter()->format(
            "! item\n",
            new FormatOptions(normalizeSectionAdornments: false, bulletMarker: null),
            $this->profile(),
        );

        self::assertSame("! item\n", $result->bytes);
        self::assertSame(1, $result->skippedExtensionPasses);
    }

    private function profile(): Profile
    {
        $roleHandler = new class implements RoleHandler {
            public function name(): string
            {
                return 'badge';
            }

            public function renderHtml(
                InterpretedText $role,
                Source $source,
                Profile $profile,
                HtmlPolicy $htmlPolicy,
            ): string {
                return '<mark>' . htmlspecialchars($role->text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</mark>';
            }

            public function convertToMarkdown(
                InterpretedText $role,
                Source $source,
                Profile $profile,
                ConversionOptions $options,
            ): ConversionResult {
                return new ConversionResult('**' . $role->text . '**', new ConversionReport());
            }
        };
        $lintRule = new class implements SourceRule {
            public function code(): string
            {
                return 'lint/test-extension';
            }

            public function check(Document $document, Source $source, ProblemCollector $problems): void
            {
                $problems->add(new Problem(
                    ProblemSeverity::Info,
                    $this->code(),
                    'Extension lint rule ran.',
                    ByteSpan::of(0, 1),
                ));
            }
        };
        $fixPass = new class implements FixPass {
            public function name(): string
            {
                return 'test-fix';
            }

            public function patches(Document $document, Source $source, Profile $profile): array
            {
                return str_starts_with($source->bytes, 'teh')
                    ? [new SourcePatch(ByteSpan::of(0, 3), 'the')]
                    : [];
            }
        };
        $formatterPass = new class implements FormatterPass {
            public function name(): string
            {
                return 'test-format';
            }

            public function patches(Document $document, Source $source, Profile $profile): array
            {
                if (str_starts_with($source->bytes, '*')) {
                    return [new SourcePatch(ByteSpan::of(0, 1), '-')];
                }

                return str_starts_with($source->bytes, '!')
                    ? [new SourcePatch(ByteSpan::of(0, 1), '*')]
                    : [];
            }
        };
        $statistics = new class implements StatisticsProvider {
            public function name(): string
            {
                return 'test';
            }

            public function collect(Document $document, Source $source, Profile $profile): array
            {
                return ['bytes' => \strlen($source->bytes)];
            }
        };
        $extension = new readonly class ($roleHandler, $lintRule, $fixPass, $formatterPass, $statistics) extends AbstractExtension {
            public function __construct(
                private readonly RoleHandler $roleHandler,
                private readonly SourceRule $lintRule,
                private readonly FixPass $fixPass,
                private readonly FormatterPass $formatterPass,
                private readonly StatisticsProvider $statistics,
            ) {}

            public function name(): string
            {
                return 'test';
            }

            public function roles(): array
            {
                return [new RoleSpec('badge')];
            }

            public function roleHandlers(): array
            {
                return [$this->roleHandler];
            }

            public function lintRules(): array
            {
                return [$this->lintRule];
            }

            public function fixPasses(): array
            {
                return [$this->fixPass];
            }

            public function formatterPasses(): array
            {
                return [$this->formatterPass];
            }

            public function statisticsProviders(): array
            {
                return [$this->statistics];
            }
        };

        return Profile::docutils()->withExtension($extension);
    }
}
