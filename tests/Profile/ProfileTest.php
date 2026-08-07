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

use Alto\Rst\Extension\Symfony\SymfonyExtension;
use Alto\Rst\Profile\Profile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Profile::class)]
#[CoversClass(SymfonyExtension::class)]
final class ProfileTest extends TestCase
{
    private const array DOCUTILS_DIRECTIVES = [
        'attention', 'caution', 'danger', 'error', 'hint', 'important',
        'note', 'tip', 'warning', 'admonition', 'image', 'figure', 'topic',
        'sidebar', 'line-block', 'parsed-literal', 'code', 'math', 'rubric',
        'code-block', 'sourcecode', 'epigraph', 'highlights', 'pull-quote', 'compound', 'container',
        'table', 'csv-table', 'list-table', 'contents', 'sectnum', 'header',
        'footer', 'target-notes', 'include', 'meta', 'replace', 'unicode', 'date',
        'class', 'role', 'default-role', 'title',
    ];

    private const array DOCUTILS_ROLE_LOOKUPS = [
        'emphasis', 'strong', 'literal', 'code', 'math', 'pep-reference',
        'rfc-reference', 'subscript', 'superscript', 'title-reference',
        'raw', 'pep', 'rfc', 'sub', 'sup', 'title',
    ];

    private const array SPHINX_DIRECTIVES = [
        'toctree', 'versionadded', 'versionchanged', 'deprecated', 'seealso',
        'literalinclude', 'highlight', 'index', 'glossary',
        'productionlist', 'only', 'centered', 'hlist', 'tabularcolumns',
    ];

    private const array SPHINX_ROLE_LOOKUPS = [
        'ref', 'doc', 'download', 'numref', 'envvar', 'token', 'keyword',
        'option', 'term', 'abbr', 'command', 'file', 'guilabel',
        'menuselection', 'kbd', 'mailheader', 'makevar', 'manpage',
        'mimetype', 'newsgroup', 'program', 'regexp', 'samp',
        'py:class', 'py:func', 'py:meth', 'py:mod', 'py:data', 'py:exc', 'py:attr',
        'func', 'meth', 'mod', 'data', 'exc', 'attr',
    ];

    private const array SYMFONY_DIRECTIVES = [
        'configuration-block', 'best-practice', 'screencast',
    ];

    private const array SYMFONY_ROLE_LOOKUPS = [
        'namespace', 'class', 'method', 'phpclass', 'phpmethod', 'phpfunction',
    ];

    public function testDocutilsProfileName(): void
    {
        self::assertSame('docutils', Profile::docutils()->name);
    }

    public function testSphinxProfileName(): void
    {
        self::assertSame('sphinx', Profile::sphinx()->name);
    }

    public function testSymfonyProfileName(): void
    {
        self::assertSame('symfony', Profile::symfony()->name);
    }

    public function testSymfonyProfileIsCompiledFromItsExtension(): void
    {
        $extensions = Profile::symfony()->extensions->all();

        self::assertCount(1, $extensions);
        self::assertInstanceOf(SymfonyExtension::class, $extensions[0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function docutilsDirectiveNames(): iterable
    {
        foreach (self::DOCUTILS_DIRECTIVES as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('docutilsDirectiveNames')]
    public function testDocutilsProfileRecognizesEveryStandardDirective(string $name): void
    {
        self::assertTrue(Profile::docutils()->directives->has($name));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function docutilsRoleLookups(): iterable
    {
        foreach (self::DOCUTILS_ROLE_LOOKUPS as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('docutilsRoleLookups')]
    public function testDocutilsProfileRecognizesEveryStandardRoleAndAbbreviation(string $name): void
    {
        self::assertTrue(Profile::docutils()->roles->has($name));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sphinxDirectiveNames(): iterable
    {
        foreach ([...self::DOCUTILS_DIRECTIVES, ...self::SPHINX_DIRECTIVES] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('sphinxDirectiveNames')]
    public function testSphinxProfileIsAdditiveOverDocutilsForDirectives(string $name): void
    {
        self::assertTrue(Profile::sphinx()->directives->has($name));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sphinxRoleLookups(): iterable
    {
        foreach ([...self::DOCUTILS_ROLE_LOOKUPS, ...self::SPHINX_ROLE_LOOKUPS] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('sphinxRoleLookups')]
    public function testSphinxProfileIsAdditiveOverDocutilsForRoles(string $name): void
    {
        self::assertTrue(Profile::sphinx()->roles->has($name));
    }

    public function testSphinxProfileAliasesClassToThePythonDomainRole(): void
    {
        self::assertSame('py:class', Profile::sphinx()->roles->get('class')?->name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function symfonyDirectiveNames(): iterable
    {
        foreach ([...self::DOCUTILS_DIRECTIVES, ...self::SPHINX_DIRECTIVES, ...self::SYMFONY_DIRECTIVES] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('symfonyDirectiveNames')]
    public function testSymfonyProfileIsAdditiveOverSphinxForDirectives(string $name): void
    {
        self::assertTrue(Profile::symfony()->directives->has($name));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function symfonyRoleLookups(): iterable
    {
        foreach ([...self::DOCUTILS_ROLE_LOOKUPS, ...self::SPHINX_ROLE_LOOKUPS, ...self::SYMFONY_ROLE_LOOKUPS] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('symfonyRoleLookups')]
    public function testSymfonyProfileIsAdditiveOverSphinxForRoles(string $name): void
    {
        self::assertTrue(Profile::symfony()->roles->has($name));
    }

    public function testSymfonyProfileOverridesTheClassRoleToThePhpMeaning(): void
    {
        $symfony = Profile::symfony();

        self::assertSame('class', $symfony->roles->get('class')?->name);
        // The Python domain role is still reachable under its own name;
        // only the shared `class` lookup changes meaning.
        self::assertSame('py:class', $symfony->roles->get('py:class')?->name);
    }

    /**
     * @return iterable<string, array{Profile}>
     */
    public static function profiles(): iterable
    {
        yield 'docutils' => [Profile::docutils()];
        yield 'sphinx' => [Profile::sphinx()];
        yield 'symfony' => [Profile::symfony()];
    }

    #[DataProvider('profiles')]
    public function testNoProfileEverEnablesTheIncludeDirective(Profile $profile): void
    {
        self::assertFalse($profile->directives->isEnabled('include'));
    }

    #[DataProvider('profiles')]
    public function testNoProfileEverEnablesTheRawDirective(Profile $profile): void
    {
        self::assertFalse($profile->directives->isEnabled('raw'));
    }

    #[DataProvider('profiles')]
    public function testCsvTableIsEnabledButItsFileAndUrlOptionsStayFlagged(Profile $profile): void
    {
        self::assertTrue($profile->directives->isEnabled('csv-table'));

        $spec = $profile->directives->get('csv-table');
        self::assertNotNull($spec);
        self::assertTrue($spec->isFileReadingOption('file'));
        self::assertTrue($spec->isFileReadingOption('url'));
    }

    #[DataProvider('profiles')]
    public function testNoProfileEverEnablesTheLiteralincludeDirective(Profile $profile): void
    {
        // literalinclude reads a whole file through its argument, not an
        // option: it belongs on the deny list, not on a per-option flag.
        self::assertFalse($profile->directives->isEnabled('literalinclude'));
    }

    public function testDocutilsProfileDoesNotKnowLiteralinclude(): void
    {
        self::assertFalse(Profile::docutils()->directives->has('literalinclude'));
    }

    /**
     * @return iterable<string, array{Profile}>
     */
    public static function profilesWithLiteralinclude(): iterable
    {
        yield 'sphinx' => [Profile::sphinx()];
        yield 'symfony' => [Profile::symfony()];
    }

    #[DataProvider('profilesWithLiteralinclude')]
    public function testSphinxAndSymfonyProfilesKnowLiteralincludeButDoNotEnableIt(Profile $profile): void
    {
        // Known but not enabled: the directive's shape is still on
        // record, the engine just refuses to turn it on.
        self::assertTrue($profile->directives->has('literalinclude'));
        self::assertFalse($profile->directives->isEnabled('literalinclude'));
    }

    public function testNoteDirectiveRecordsItsShapeNotJustItsName(): void
    {
        $spec = Profile::docutils()->directives->get('note');

        self::assertNotNull($spec);
        self::assertFalse($spec->hasArgument);
        self::assertSame(['class', 'name'], $spec->options);
        self::assertTrue($spec->hasBody);
    }

    public function testImageDirectiveTakesAnArgumentAndHasNoBody(): void
    {
        $spec = Profile::docutils()->directives->get('image');

        self::assertNotNull($spec);
        self::assertTrue($spec->hasArgument);
        self::assertTrue($spec->hasOption('alt'));
        self::assertFalse($spec->hasBody);
    }

    public function testCodeBlockDirectiveRecordsSphinxOptions(): void
    {
        $spec = Profile::sphinx()->directives->get('code-block');

        self::assertNotNull($spec);
        self::assertTrue($spec->hasArgument);
        self::assertTrue($spec->hasOption('linenos'));
        self::assertTrue($spec->hasOption('caption'));
    }

    public function testPepReferenceRoleCarriesItsAbbreviationAsAnAlias(): void
    {
        $role = Profile::docutils()->roles->get('pep-reference');

        self::assertNotNull($role);
        self::assertSame(['pep'], $role->aliases);
    }
}
