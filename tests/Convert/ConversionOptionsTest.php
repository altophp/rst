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

namespace Alto\Rst\Tests\Convert;

use Alto\Rst\Convert\AdmonitionStyle;
use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\FenceStyle;
use Alto\Rst\Convert\HeadingStyle;
use Alto\Rst\Convert\LinkStyle;
use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Security\FileAccessPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConversionOptions::class)]
final class ConversionOptionsTest extends TestCase
{
    public function testDefaultsFavourFidelity(): void
    {
        $options = new ConversionOptions();

        self::assertSame(FenceStyle::Backtick, $options->fenceStyle);
        self::assertSame(LinkStyle::Preserve, $options->linkStyle);
        self::assertSame(HeadingStyle::Atx, $options->headingStyle);
        self::assertSame(AdmonitionStyle::GithubAlert, $options->admonitionStyle);
        self::assertSame('-', $options->bulletMarker);
        self::assertSame(4, $options->indentWidth);
        self::assertSame('code-block', $options->codeBlockDirective);
        self::assertNull($options->fileAccessPolicy);
        self::assertFalse($options->implicitSectionReferences);
        self::assertFalse($options->allowRawHtml);
    }

    public function testSymfonyPresetUsesTheDocumentedAdornmentOrder(): void
    {
        $options = ConversionOptions::symfony();

        self::assertSame(['=', '-', '~', '.', '"'], $options->sectionAdornments);
        self::assertSame('code-block', $options->codeBlockDirective);
        self::assertTrue($options->implicitSectionReferences);
        self::assertFalse($options->allowRawHtml);
    }

    public function testSymfonyPresetAcceptsAnExplicitFilePolicy(): void
    {
        $policy = FileAccessPolicy::rootedAt(sys_get_temp_dir());

        self::assertSame($policy, ConversionOptions::symfony($policy)->fileAccessPolicy);
    }

    public function testSymfonyPresetRequiresSeparateRawHtmlAuthority(): void
    {
        $policy = FileAccessPolicy::rootedAt(sys_get_temp_dir());

        self::assertFalse(ConversionOptions::symfony($policy)->allowRawHtml);
        self::assertTrue(ConversionOptions::symfony($policy, true)->allowRawHtml);
    }

    #[DataProvider('adornmentLevels')]
    public function testAdornmentForClampsOutOfRangeLevels(int $level, string $expected): void
    {
        self::assertSame($expected, ConversionOptions::symfony()->adornmentFor($level));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function adornmentLevels(): iterable
    {
        yield 'first level' => [1, '='];
        yield 'third level' => [3, '~'];
        yield 'last defined level' => [5, '"'];
        yield 'beyond the last level' => [9, '"'];
        yield 'zero clamps to the first' => [0, '='];
        yield 'negative clamps to the first' => [-3, '='];
    }

    public function testIndentRepeatsTheConfiguredWidth(): void
    {
        $options = new ConversionOptions(indentWidth: 3);

        self::assertSame('', $options->indent(0));
        self::assertSame('   ', $options->indent());
        self::assertSame('      ', $options->indent(2));
        self::assertSame('', $options->indent(-1));
    }

    public function testRejectsAnUnknownBulletMarker(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bullet marker must be one of');

        new ConversionOptions(bulletMarker: 'o');
    }

    public function testRejectsAMultiCharacterBulletMarker(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConversionOptions(bulletMarker: '--');
    }

    public function testRejectsEmptySectionAdornments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        new ConversionOptions(sectionAdornments: []);
    }

    #[DataProvider('invalidAdornments')]
    public function testRejectsAnInvalidSectionAdornment(string $adornment): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid section adornment');

        new ConversionOptions(sectionAdornments: ['=', $adornment]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAdornments(): iterable
    {
        yield 'alphanumeric' => ['a'];
        yield 'space' => [' '];
        yield 'two characters' => ['=='];
        yield 'empty' => [''];
    }

    public function testRejectsANonPositiveIndentWidth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Indent width must be >= 1');

        new ConversionOptions(indentWidth: 0);
    }

    public function testRejectsAnEmptyCodeBlockDirective(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Code block directive name must not be empty');

        new ConversionOptions(codeBlockDirective: '');
    }
}
