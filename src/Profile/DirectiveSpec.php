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

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Node\DirectiveBodyKind;

/**
 * A directive's static capability description: what a directive of this
 * name accepts, not what it does. Parsers and renderers consult a
 * profile's DirectiveSet to know whether a name is recognized and how to
 * read its head (argument, options) and body.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DirectiveSpec
{
    public DirectiveBodyKind $bodyKind;

    /**
     * @param list<string> $options            known option field names, lowercase
     * @param list<string> $fileReadingOptions subset of $options that read from the
     *                                         file system (or network) when given a
     *                                         value; per AGENTS.md these stay disabled
     *                                         without an explicit file-access policy,
     *                                         even though the directive itself is known
     */
    public function __construct(
        public string $name,
        public bool $hasArgument,
        public array $options = [],
        public bool $hasBody = true,
        public array $fileReadingOptions = [],
        ?DirectiveBodyKind $bodyKind = null,
    ) {
        $this->bodyKind = $bodyKind ?? ($hasBody ? DirectiveBodyKind::Blocks : DirectiveBodyKind::None);

        if ($hasBody === (DirectiveBodyKind::None === $this->bodyKind)) {
            throw new InvalidArgumentException('Directive body kind must agree with whether the directive accepts a body.');
        }
    }

    /**
     * Whether this directive declares the given option, case-insensitive
     * on ASCII.
     */
    public function hasOption(string $option): bool
    {
        return in_array(strtolower($option), $this->options, true);
    }

    /**
     * Whether supplying the given option would require reading from the
     * file system or network, per AGENTS.md's file-reading directive
     * policy. The directive itself can still be known and enabled; only
     * this option stays blocked without an explicit policy.
     */
    public function isFileReadingOption(string $option): bool
    {
        return in_array(strtolower($option), $this->fileReadingOptions, true);
    }
}
