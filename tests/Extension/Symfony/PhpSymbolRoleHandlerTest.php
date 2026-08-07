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

namespace Alto\Rst\Tests\Extension\Symfony;

use Alto\Rst\Convert\ConversionResult;
use Alto\Rst\Convert\IssueKind;
use Alto\Rst\Convert\RstToMarkdown;
use Alto\Rst\Extension\Symfony\PhpSymbolRoleHandler;
use Alto\Rst\Extension\Symfony\SymfonyExtension;
use Alto\Rst\Parser\BlockParser;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Render\HtmlRenderer;
use Alto\Rst\Render\RenderOptions;
use Alto\Rst\Source\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpSymbolRoleHandler::class)]
#[CoversClass(SymfonyExtension::class)]
final class PhpSymbolRoleHandlerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function simpleSymbols(): iterable
    {
        yield 'class' => ['class', 'Symfony\\\\Component\\\\HttpKernel\\\\Kernel', 'Symfony\\Component\\HttpKernel\\Kernel'];
        yield 'method' => ['method', 'Kernel::boot()', 'Kernel::boot()'];
        yield 'phpclass' => ['phpclass', 'DateTimeImmutable', 'DateTimeImmutable'];
        yield 'phpmethod' => ['phpmethod', 'DateTime::createFromFormat()', 'DateTime::createFromFormat()'];
        yield 'phpfunction' => ['phpfunction', 'strlen()', 'strlen()'];
    }

    #[DataProvider('simpleSymbols')]
    public function testSimpleSymbolsRenderAsCodeWithoutAnApproximation(
        string $role,
        string $sourceText,
        string $visibleText,
    ): void {
        $rst = sprintf(':%s:`%s`', $role, $sourceText);

        self::assertSame(
            '<p><code>'.htmlspecialchars($visibleText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</code></p>\n",
            self::renderHtml($rst, Profile::symfony()),
        );

        $conversion = self::convert($rst, Profile::symfony());

        self::assertSame('`'.$visibleText."`\n", $conversion->output);
        self::assertTrue($conversion->report->isEmpty());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function explicitTitles(): iterable
    {
        yield 'class' => ['class', 'Kernel', 'Symfony\\\\Component\\\\HttpKernel\\\\Kernel'];
        yield 'method' => ['method', 'boot the kernel', 'Kernel::boot()'];
        yield 'phpclass' => ['phpclass', 'immutable date', 'DateTimeImmutable'];
        yield 'phpmethod' => ['phpmethod', 'create a date', 'DateTime::createFromFormat()'];
        yield 'phpfunction' => ['phpfunction', 'measure a string', 'strlen()'];
    }

    #[DataProvider('explicitTitles')]
    public function testExplicitTitlesStayVisibleAndReportTheDroppedTarget(
        string $role,
        string $title,
        string $target,
    ): void {
        $rst = sprintf(':%s:`%s <%s>`', $role, $title, $target);

        self::assertSame(
            '<p><code>'.$title."</code></p>\n",
            self::renderHtml($rst, Profile::symfony()),
        );

        $conversion = self::convert($rst, Profile::symfony());
        $issues = $conversion->report->issues;

        self::assertSame('`'.$title."`\n", $conversion->output);
        self::assertCount(1, $issues);
        self::assertSame(IssueKind::Lossy, $issues[0]->kind);
        self::assertSame('role:'.$role, $issues[0]->construct);
        self::assertStringContainsString(str_replace('\\\\', '\\', $target), $issues[0]->message);
        self::assertStringContainsString($title, $issues[0]->message);
        self::assertNotNull($issues[0]->span);
    }

    public function testGenericLookingClassNameIsNotTreatedAsAnExplicitTitle(): void
    {
        $rst = ':class:`Foo\\\\Bar<T>`';

        self::assertSame(
            "<p><code>Foo\\Bar&lt;T&gt;</code></p>\n",
            self::renderHtml($rst, Profile::symfony()),
        );

        $conversion = self::convert($rst, Profile::symfony());

        self::assertSame("`Foo\\Bar<T>`\n", $conversion->output);
        self::assertTrue($conversion->report->isEmpty());
    }

    public function testHandlerIsRestrictedToTheSymfonyProfile(): void
    {
        $rst = ':class:`Foo\\\\Bar`';

        self::assertSame(
            "<p>Foo\\Bar</p>\n",
            self::renderHtml($rst, Profile::sphinx()),
        );

        $conversion = self::convert($rst, Profile::sphinx());

        self::assertSame("`Foo\\Bar`\n", $conversion->output);
        self::assertSame(['role:class' => 1], $conversion->report->countsByConstruct());
        self::assertSame(IssueKind::Lossy, $conversion->report->issues[0]->kind);
        self::assertNull(Profile::sphinx()->extensions->roleHandler('class'));
        self::assertInstanceOf(
            PhpSymbolRoleHandler::class,
            Profile::symfony()->extensions->roleHandler('CLASS'),
        );
    }

    public function testHtmlEscapesSymbolContent(): void
    {
        self::assertSame(
            '<p><code>Foo&lt;&amp;&gt;</code></p>'."\n",
            self::renderHtml(':phpclass:`Foo<&>`', Profile::symfony()),
        );
    }

    public function testMarkdownCodeSpanExpandsAroundBackticks(): void
    {
        $conversion = self::convert(':phpclass:`Foo``Bar`', Profile::symfony());

        self::assertSame("```Foo``Bar```\n", $conversion->output);
    }

    public function testSymfonyExtensionExposesItsStableName(): void
    {
        self::assertSame('symfony', new SymfonyExtension()->name());
    }

    private static function renderHtml(string $rst, Profile $profile): string
    {
        $source = Source::fromString($rst);
        $result = new BlockParser()->parse($source, $profile);

        return new HtmlRenderer()->render(
            $result->document(),
            $source,
            new RenderOptions(profile: $profile),
            $result->references(),
        );
    }

    private static function convert(string $rst, Profile $profile): ConversionResult
    {
        $source = Source::fromString($rst);
        $result = new BlockParser()->parse($source, $profile);

        return new RstToMarkdown()->convert(
            $result->document(),
            $source,
            $profile,
            references: $result->references(),
        );
    }
}
