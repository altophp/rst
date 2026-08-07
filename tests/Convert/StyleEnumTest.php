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
use Alto\Rst\Convert\FenceStyle;
use Alto\Rst\Convert\HeadingStyle;
use Alto\Rst\Convert\IssueKind;
use Alto\Rst\Convert\LinkStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdmonitionStyle::class)]
#[CoversClass(FenceStyle::class)]
#[CoversClass(HeadingStyle::class)]
#[CoversClass(IssueKind::class)]
#[CoversClass(LinkStyle::class)]
final class StyleEnumTest extends TestCase
{
    #[DataProvider('fences')]
    public function testFenceNeverGoesBelowThreeCharacters(FenceStyle $style, int $length, string $expected): void
    {
        self::assertSame($expected, $style->fence($length));
    }

    /**
     * @return iterable<string, array{FenceStyle, int, string}>
     */
    public static function fences(): iterable
    {
        yield 'backtick default' => [FenceStyle::Backtick, 3, '```'];
        yield 'backtick longer' => [FenceStyle::Backtick, 5, '`````'];
        yield 'backtick clamped' => [FenceStyle::Backtick, 1, '```'];
        yield 'tilde default' => [FenceStyle::Tilde, 3, '~~~'];
        yield 'tilde clamped' => [FenceStyle::Tilde, 0, '~~~'];
    }

    public function testFenceDefaultsToThree(): void
    {
        self::assertSame('```', FenceStyle::Backtick->fence());
    }

    public function testGithubAlertNamesCoverTheFiveSupportedAdmonitions(): void
    {
        $names = AdmonitionStyle::githubAlertNames();

        self::assertSame(['note', 'tip', 'important', 'warning', 'caution'], array_keys($names));
        self::assertSame('NOTE', $names['note']);
        self::assertSame('CAUTION', $names['caution']);
    }

    public function testEnumValuesAreStable(): void
    {
        self::assertSame('`', FenceStyle::Backtick->value);
        self::assertSame('~', FenceStyle::Tilde->value);
        self::assertSame('atx', HeadingStyle::Atx->value);
        self::assertSame('setext', HeadingStyle::Setext->value);
        self::assertSame('preserve', LinkStyle::Preserve->value);
        self::assertSame('inline', LinkStyle::Inline->value);
        self::assertSame('reference', LinkStyle::Reference->value);
        self::assertSame('blockquote', AdmonitionStyle::Blockquote->value);
        self::assertSame('github-alert', AdmonitionStyle::GithubAlert->value);
        self::assertSame('unsupported', IssueKind::Unsupported->value);
        self::assertSame('lossy', IssueKind::Lossy->value);
        self::assertSame('approximated', IssueKind::Approximated->value);
    }
}
