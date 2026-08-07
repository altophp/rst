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

/**
 * An immutable, additive set of directive capabilities. Membership lookup
 * is case-insensitive on ASCII, matching the rest of the engine (see
 * `Alto\Rst\Lint\Rule\DuplicateTargetRule::normalize`).
 *
 * File-reading directives never run without an explicit file-access
 * policy, per AGENTS.md. This is a category, not a fixed enumeration: any
 * directive that pulls a whole file or URL into the document at parse
 * time through its name or its required argument belongs in
 * `FILE_READING_DIRECTIVES` and stays unconditionally disabled --
 * `include` (reads a file by argument), `raw` (embeds arbitrary raw
 * output, itself readable from a file or URL via options), and
 * `literalinclude` (reads a file by argument, Sphinx). A directive whose
 * risk is narrower than the whole construct, because only specific
 * options read external content, is not added here; instead its spec
 * flags those options through `DirectiveSpec::$fileReadingOptions` and
 * `DirectiveSpec::isFileReadingOption()` -- `csv-table`'s `:file:` and
 * `:url:` options are the example: the directive itself works from an
 * inline body and stays enabled, only those two options are refused.
 * Adding a new directive that can read a path or a URL must extend one of
 * these two mechanisms; a name absent from both is not evidence that it
 * is safe.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DirectiveSet
{
    /**
     * Directive names that read an entire file or URL into the document
     * at parse time and therefore stay disabled unconditionally, with no
     * per-option carve-out. See the class docblock.
     *
     * @var list<string>
     */
    private const array FILE_READING_DIRECTIVES = ['include', 'raw', 'literalinclude'];

    /**
     * @param array<string, DirectiveSpec> $directives keyed by normalized name
     */
    private function __construct(
        private array $directives = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @param list<DirectiveSpec> $directives
     */
    public static function of(array $directives): self
    {
        $set = new self();

        foreach ($directives as $directive) {
            $set = $set->with($directive);
        }

        return $set;
    }

    /**
     * Adds or replaces a directive; an existing entry with the same
     * normalized name is overridden in place.
     */
    public function with(DirectiveSpec $directive): self
    {
        $directives = $this->directives;
        $directives[self::normalize($directive->name)] = $directive;

        return new self($directives);
    }

    /**
     * Additive merge: entries from $other override entries of this set
     * that share the same normalized name. This is how `sphinx()` extends
     * `docutils()` and `symfony()` extends `sphinx()`.
     */
    public function merge(self $other): self
    {
        return new self([...$this->directives, ...$other->directives]);
    }

    public function has(string $name): bool
    {
        return isset($this->directives[self::normalize($name)]);
    }

    public function get(string $name): ?DirectiveSpec
    {
        return $this->directives[self::normalize($name)] ?? null;
    }

    /**
     * True when the directive is known and is not one of the file-reading
     * directives that stay disabled without an explicit file-access
     * policy. Names in `FILE_READING_DIRECTIVES` (`include`, `raw`,
     * `literalinclude`) always report false here, even if a spec happens
     * to be registered for them: knowing a directive is not the same as
     * enabling it. A directive with only a file-reading option, such as
     * `csv-table`, still reports true: the option is what stays blocked,
     * not the directive; see `DirectiveSpec::isFileReadingOption()`.
     */
    public function isEnabled(string $name): bool
    {
        $normalized = self::normalize($name);

        if (in_array($normalized, self::FILE_READING_DIRECTIVES, true)) {
            return false;
        }

        return isset($this->directives[$normalized]);
    }

    /**
     * @return list<string> known directive names, insertion order
     */
    public function names(): array
    {
        return array_map(
            static fn (DirectiveSpec $directive): string => $directive->name,
            array_values($this->directives),
        );
    }

    private static function normalize(string $name): string
    {
        return strtolower($name);
    }
}
