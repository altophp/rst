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

use Alto\Rst\Profile\DirectiveSet;
use Alto\Rst\Profile\DirectiveSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DirectiveSet::class)]
final class DirectiveSetTest extends TestCase
{
    public function testEmptySetHasNothing(): void
    {
        $set = DirectiveSet::empty();

        self::assertFalse($set->has('note'));
        self::assertNull($set->get('note'));
        self::assertSame([], $set->names());
    }

    public function testOfBuildsASetFromAList(): void
    {
        $set = DirectiveSet::of([
            new DirectiveSpec('note', false),
            new DirectiveSpec('image', true, [], false),
        ]);

        self::assertTrue($set->has('note'));
        self::assertTrue($set->has('image'));
        self::assertSame(['note', 'image'], $set->names());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function caseVariants(): iterable
    {
        yield 'lowercase' => ['note'];
        yield 'uppercase' => ['NOTE'];
        yield 'mixed case' => ['NoTe'];
    }

    #[DataProvider('caseVariants')]
    public function testMembershipLookupIsCaseInsensitiveOnAscii(string $name): void
    {
        $set = DirectiveSet::of([new DirectiveSpec('note', false)]);

        self::assertTrue($set->has($name));

        $spec = $set->get($name);
        self::assertNotNull($spec);
        self::assertSame('note', $spec->name);
    }

    public function testGetReturnsNullForUnknownDirective(): void
    {
        $set = DirectiveSet::of([new DirectiveSpec('note', false)]);

        self::assertNull($set->get('bogus'));
    }

    public function testWithOverridesAnExistingEntryWithTheSameName(): void
    {
        $set = DirectiveSet::of([new DirectiveSpec('note', false, [], true)]);
        $replacement = new DirectiveSpec('note', true, ['class'], false);

        $updated = $set->with($replacement);

        self::assertSame($replacement, $updated->get('note'));
        // The original set is unchanged: DirectiveSet is immutable.
        self::assertNotSame($replacement, $set->get('note'));
    }

    public function testMergeIsAdditive(): void
    {
        $base = DirectiveSet::of([new DirectiveSpec('note', false)]);
        $extra = DirectiveSet::of([new DirectiveSpec('toctree', false)]);

        $merged = $base->merge($extra);

        self::assertTrue($merged->has('note'));
        self::assertTrue($merged->has('toctree'));
    }

    public function testMergeOverridesSharedNamesWithTheOtherSet(): void
    {
        $base = DirectiveSet::of([new DirectiveSpec('note', false, [], true)]);
        $override = DirectiveSet::of([new DirectiveSpec('note', true, ['class'], false)]);

        $merged = $base->merge($override);
        $spec = $merged->get('note');

        self::assertNotNull($spec);
        self::assertTrue($spec->hasArgument);
        self::assertSame(['class'], $spec->options);
        self::assertFalse($spec->hasBody);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fileReadingDirectiveNames(): iterable
    {
        yield 'include' => ['include'];
        yield 'raw' => ['raw'];
        yield 'literalinclude' => ['literalinclude'];
        yield 'include, uppercase' => ['INCLUDE'];
        yield 'raw, uppercase' => ['RAW'];
        yield 'literalinclude, uppercase' => ['LITERALINCLUDE'];
    }

    #[DataProvider('fileReadingDirectiveNames')]
    public function testFileReadingDirectivesAreNeverEnabled(string $name): void
    {
        // No profile registers include/raw/literalinclude in this bare
        // set, but the constraint from AGENTS.md must hold regardless of
        // registration.
        $set = DirectiveSet::of([new DirectiveSpec('note', false)]);

        self::assertFalse($set->isEnabled($name));
    }

    /**
     * @return iterable<string, array{DirectiveSpec}>
     */
    public static function registeredFileReadingDirectives(): iterable
    {
        yield 'include' => [new DirectiveSpec('include', true, ['literal'])];
        yield 'raw' => [new DirectiveSpec('raw', true, ['file', 'url'])];
        yield 'literalinclude' => [new DirectiveSpec('literalinclude', true, ['language'], false)];
    }

    #[DataProvider('registeredFileReadingDirectives')]
    public function testFileReadingDirectivesStayDisabledEvenIfRegistered(DirectiveSpec $directive): void
    {
        $set = DirectiveSet::of([$directive]);

        // Known but not enabled: the set still records the directive's
        // shape, it just refuses to turn it on.
        self::assertTrue($set->has($directive->name));
        self::assertFalse($set->isEnabled($directive->name));
    }

    public function testAnUnknownDirectiveIsNotEnabled(): void
    {
        $set = DirectiveSet::empty();

        self::assertFalse($set->isEnabled('note'));
    }

    public function testAKnownNonFileReadingDirectiveIsEnabled(): void
    {
        $set = DirectiveSet::of([new DirectiveSpec('note', false)]);

        self::assertTrue($set->isEnabled('note'));
    }

    public function testCsvTableItselfIsEnabledButItsFileAndUrlOptionsAreFlagged(): void
    {
        $set = DirectiveSet::of([
            new DirectiveSpec('csv-table', true, ['header', 'file', 'url'], true, ['file', 'url']),
        ]);

        self::assertTrue($set->isEnabled('csv-table'));

        $spec = $set->get('csv-table');
        self::assertNotNull($spec);
        self::assertTrue($spec->isFileReadingOption('file'));
        self::assertTrue($spec->isFileReadingOption('url'));
        self::assertFalse($spec->isFileReadingOption('header'));
    }

    public function testNamesReflectsRegisteredDirectiveNamesNotAliases(): void
    {
        $set = DirectiveSet::of([
            new DirectiveSpec('note', false),
            new DirectiveSpec('image', true, [], false),
        ]);

        self::assertSame(['note', 'image'], $set->names());
    }
}
