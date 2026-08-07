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

namespace Alto\Rst\Tests\Profile;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Node\DirectiveBodyKind;
use Alto\Rst\Profile\DirectiveSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DirectiveSpec::class)]
final class DirectiveSpecTest extends TestCase
{
    public function testConstructorStoresShape(): void
    {
        $spec = new DirectiveSpec('image', true, ['alt', 'class'], false, []);

        self::assertSame('image', $spec->name);
        self::assertTrue($spec->hasArgument);
        self::assertSame(['alt', 'class'], $spec->options);
        self::assertFalse($spec->hasBody);
        self::assertSame(DirectiveBodyKind::None, $spec->bodyKind);
        self::assertSame([], $spec->fileReadingOptions);
    }

    public function testDefaultsHaveBodyAndNoOptions(): void
    {
        $spec = new DirectiveSpec('note', false);

        self::assertSame([], $spec->options);
        self::assertTrue($spec->hasBody);
        self::assertSame(DirectiveBodyKind::Blocks, $spec->bodyKind);
        self::assertSame([], $spec->fileReadingOptions);
    }

    public function testLiteralBodyKindIsExplicit(): void
    {
        $spec = new DirectiveSpec('code', true, bodyKind: DirectiveBodyKind::Literal);

        self::assertSame(DirectiveBodyKind::Literal, $spec->bodyKind);
    }

    public function testBodyKindCannotContradictBodyCapability(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DirectiveSpec('broken', false, hasBody: false, bodyKind: DirectiveBodyKind::Blocks);
    }

    public function testNoneBodyKindRequiresABodylessDirective(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DirectiveSpec('broken', false, bodyKind: DirectiveBodyKind::None);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function optionCases(): iterable
    {
        yield 'known option matches' => ['alt', true];
        yield 'known option matches case-insensitively' => ['ALT', true];
        yield 'unknown option does not match' => ['bogus', false];
    }

    #[DataProvider('optionCases')]
    public function testHasOption(string $option, bool $expected): void
    {
        $spec = new DirectiveSpec('image', true, ['alt', 'class']);

        self::assertSame($expected, $spec->hasOption($option));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function fileReadingOptionCases(): iterable
    {
        yield 'file option is flagged' => ['file', true];
        yield 'file option is flagged case-insensitively' => ['FILE', true];
        yield 'header option is not flagged' => ['header', false];
    }

    #[DataProvider('fileReadingOptionCases')]
    public function testIsFileReadingOption(string $option, bool $expected): void
    {
        $spec = new DirectiveSpec('csv-table', true, ['header', 'file'], true, ['file']);

        self::assertSame($expected, $spec->isFileReadingOption($option));
    }
}
