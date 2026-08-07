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

namespace Alto\Rst\Profile;

use Alto\Rst\Extension\Extension;
use Alto\Rst\Extension\ExtensionSet;
use Alto\Rst\Extension\Symfony\SymfonyExtension;
use Alto\Rst\Node\DirectiveBodyKind;

/**
 * A compiled capability set: which directives and roles a dialect
 * recognizes, and how. A profile is data, not a behavior switch scattered
 * through the parser; the parser and renderer consult it, they never
 * branch on a profile name.
 *
 * Each profile is additive over the previous one: `sphinx()` starts from
 * `docutils()` and adds, `symfony()` starts from `sphinx()` and adds.
 * Content is drawn from the docutils and Sphinx documentation, dimensioned
 * against the real Symfony UX documentation workload (see
 * `_dev/knowledge/UX-DOCS-CENSUS.md`), not the full breadth of either
 * language.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Profile
{
    public ExtensionSet $extensions;

    public function __construct(
        public string $name,
        public DirectiveSet $directives,
        public RoleSet $roles,
        ?ExtensionSet $extensions = null,
    ) {
        $this->extensions = $extensions ?? ExtensionSet::empty();
    }

    /**
     * reStructuredText as specified by the docutils documentation: the
     * standard directive and role set.
     */
    public static function docutils(): self
    {
        return new self(
            'docutils',
            DirectiveSet::of(self::docutilsDirectives()),
            RoleSet::of(self::docutilsRoles()),
        );
    }

    /**
     * The docutils base plus the Sphinx dialect: cross-reference roles,
     * `toctree`, versioning admonitions, literal includes, and the Python
     * domain roles.
     */
    public static function sphinx(): self
    {
        $base = self::docutils();

        return new self(
            'sphinx',
            $base->directives->merge(DirectiveSet::of(self::sphinxDirectives())),
            $base->roles->merge(RoleSet::of(self::sphinxRoles())),
        );
    }

    /**
     * The Sphinx dialect plus the Symfony documentation conventions:
     * `configuration-block` and friends, and the PHP-oriented roles found
     * in symfony/symfony-docs and symfony/ux. The Symfony `class` role
     * overrides the Sphinx `class` alias of `py:class`; see
     * `RoleSet::merge()`.
     */
    public static function symfony(): self
    {
        $base = self::sphinx();

        return new self(
            'symfony',
            $base->directives,
            $base->roles,
            $base->extensions,
        )->withExtension(new SymfonyExtension());
    }

    public function withExtension(Extension $extension): self
    {
        return new self(
            $this->name,
            $this->directives->merge(DirectiveSet::of($extension->directives())),
            $this->roles->merge(RoleSet::of($extension->roles())),
            $this->extensions->with($extension),
        );
    }

    /**
     * @return list<DirectiveSpec>
     */
    private static function docutilsDirectives(): array
    {
        $admonitionOptions = ['class', 'name'];

        return [
            new DirectiveSpec('attention', false, $admonitionOptions),
            new DirectiveSpec('caution', false, $admonitionOptions),
            new DirectiveSpec('danger', false, $admonitionOptions),
            new DirectiveSpec('error', false, $admonitionOptions),
            new DirectiveSpec('hint', false, $admonitionOptions),
            new DirectiveSpec('important', false, $admonitionOptions),
            new DirectiveSpec('note', false, $admonitionOptions),
            new DirectiveSpec('tip', false, $admonitionOptions),
            new DirectiveSpec('warning', false, $admonitionOptions),
            new DirectiveSpec('admonition', true, $admonitionOptions),
            new DirectiveSpec('image', true, ['alt', 'height', 'width', 'scale', 'align', 'target', 'class', 'name'], false),
            new DirectiveSpec('figure', true, ['alt', 'height', 'width', 'scale', 'align', 'target', 'class', 'name', 'figwidth', 'figclass']),
            new DirectiveSpec('topic', true, $admonitionOptions),
            new DirectiveSpec('sidebar', true, ['subtitle', 'class', 'name']),
            new DirectiveSpec('line-block', false, $admonitionOptions, bodyKind: DirectiveBodyKind::Opaque),
            new DirectiveSpec('parsed-literal', false, $admonitionOptions, bodyKind: DirectiveBodyKind::Opaque),
            new DirectiveSpec('code', true, ['number-lines', 'class', 'name'], bodyKind: DirectiveBodyKind::Literal),
            new DirectiveSpec('code-block', true, ['number-lines', 'class', 'name'], bodyKind: DirectiveBodyKind::Literal),
            new DirectiveSpec('sourcecode', true, ['number-lines', 'class', 'name'], bodyKind: DirectiveBodyKind::Literal),
            new DirectiveSpec('math', true, $admonitionOptions, bodyKind: DirectiveBodyKind::Literal),
            new DirectiveSpec('rubric', true, $admonitionOptions, false),
            new DirectiveSpec('epigraph', false, $admonitionOptions),
            new DirectiveSpec('highlights', false, $admonitionOptions),
            new DirectiveSpec('pull-quote', false, $admonitionOptions),
            new DirectiveSpec('compound', false, $admonitionOptions),
            new DirectiveSpec('container', true, ['name']),
            new DirectiveSpec('table', true, ['class', 'name', 'align', 'width', 'widths']),
            new DirectiveSpec('csv-table', true, ['header', 'widths', 'width', 'header-rows', 'stub-columns', 'align', 'class', 'name', 'file', 'url', 'encoding', 'delim', 'quote', 'keepspace', 'escape'], true, ['file', 'url'], DirectiveBodyKind::Opaque),
            new DirectiveSpec('list-table', true, ['header-rows', 'stub-columns', 'width', 'widths', 'align', 'class', 'name']),
            new DirectiveSpec('contents', true, ['depth', 'local', 'backlinks', 'class', 'name'], false),
            new DirectiveSpec('sectnum', false, ['depth', 'start', 'prefix', 'suffix'], false),
            new DirectiveSpec('header', false, [], true),
            new DirectiveSpec('footer', false, [], true),
            new DirectiveSpec('target-notes', false, $admonitionOptions, false),
            new DirectiveSpec('include', true, ['start-line', 'end-line', 'start-after', 'end-before', 'literal', 'code', 'number-lines', 'encoding', 'parser'], false),
            new DirectiveSpec('meta', false, [], true, bodyKind: DirectiveBodyKind::Opaque),
            new DirectiveSpec('replace', false, [], true, bodyKind: DirectiveBodyKind::Opaque),
            new DirectiveSpec('unicode', true, ['trim', 'ltrim', 'rtrim'], false),
            new DirectiveSpec('date', true, [], false),
            new DirectiveSpec('class', true, [], true),
            new DirectiveSpec('role', true, [], true, bodyKind: DirectiveBodyKind::Opaque),
            new DirectiveSpec('default-role', true, [], false),
            new DirectiveSpec('title', true, [], false),
        ];
    }

    /**
     * @return list<RoleSpec>
     */
    private static function docutilsRoles(): array
    {
        return [
            new RoleSpec('emphasis'),
            new RoleSpec('strong'),
            new RoleSpec('literal'),
            new RoleSpec('code'),
            new RoleSpec('math'),
            new RoleSpec('pep-reference', ['pep']),
            new RoleSpec('rfc-reference', ['rfc']),
            new RoleSpec('subscript', ['sub']),
            new RoleSpec('superscript', ['sup']),
            new RoleSpec('title-reference', ['title']),
            new RoleSpec('raw'),
        ];
    }

    /**
     * @return list<DirectiveSpec>
     */
    private static function sphinxDirectives(): array
    {
        return [
            new DirectiveSpec('toctree', false, ['maxdepth', 'name', 'caption', 'glob', 'hidden', 'includehidden', 'numbered', 'reversed', 'titlesonly'], bodyKind: DirectiveBodyKind::Literal),
            new DirectiveSpec('versionadded', true, []),
            new DirectiveSpec('versionchanged', true, []),
            new DirectiveSpec('deprecated', true, []),
            new DirectiveSpec('seealso', false, [], true),
            new DirectiveSpec('code-block', true, ['linenos', 'lineno-start', 'emphasize-lines', 'caption', 'name', 'dedent', 'force', 'class'], bodyKind: DirectiveBodyKind::Literal),
            new DirectiveSpec('sourcecode', true, ['linenos', 'lineno-start', 'emphasize-lines', 'caption', 'name', 'dedent', 'force', 'class'], bodyKind: DirectiveBodyKind::Literal),
            new DirectiveSpec('literalinclude', true, ['language', 'linenos', 'lineno-start', 'lineno-match', 'tab-width', 'encoding', 'pyobject', 'lines', 'start-after', 'end-before', 'start-at', 'end-at', 'prepend', 'append', 'dedent', 'caption', 'name', 'class', 'diff'], false),
            new DirectiveSpec('highlight', true, ['linenothreshold', 'force'], false),
            new DirectiveSpec('index', true, ['name'], false),
            new DirectiveSpec('glossary', false, ['sorted']),
            new DirectiveSpec('productionlist', true, [], bodyKind: DirectiveBodyKind::Literal),
            new DirectiveSpec('only', true, []),
            new DirectiveSpec('centered', true, [], false),
            new DirectiveSpec('hlist', false, ['columns']),
            new DirectiveSpec('tabularcolumns', true, [], false),
        ];
    }

    /**
     * @return list<RoleSpec>
     */
    private static function sphinxRoles(): array
    {
        return [
            new RoleSpec('ref'),
            new RoleSpec('doc'),
            new RoleSpec('download'),
            new RoleSpec('numref'),
            new RoleSpec('envvar'),
            new RoleSpec('token'),
            new RoleSpec('keyword'),
            new RoleSpec('option'),
            new RoleSpec('term'),
            new RoleSpec('abbr'),
            new RoleSpec('command'),
            new RoleSpec('file'),
            new RoleSpec('guilabel'),
            new RoleSpec('menuselection'),
            new RoleSpec('kbd'),
            new RoleSpec('mailheader'),
            new RoleSpec('makevar'),
            new RoleSpec('manpage'),
            new RoleSpec('mimetype'),
            new RoleSpec('newsgroup'),
            new RoleSpec('program'),
            new RoleSpec('regexp'),
            new RoleSpec('samp'),
            new RoleSpec('py:class', ['class']),
            new RoleSpec('py:func', ['func']),
            new RoleSpec('py:meth', ['meth']),
            new RoleSpec('py:mod', ['mod']),
            new RoleSpec('py:data', ['data']),
            new RoleSpec('py:exc', ['exc']),
            new RoleSpec('py:attr', ['attr']),
        ];
    }
}
