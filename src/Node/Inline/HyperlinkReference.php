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

namespace Alto\Rst\Node\Inline;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Node\Node;
use Alto\Rst\Source\ByteSpan;

/**
 * A hyperlink reference: `name_`, `` `phrase`_ ``, `` `text <uri>`_ ``, and
 * the anonymous `__` variants.
 *
 * `$simple` is true only for the backquote-free `name_` form. It exists for
 * round-trip fidelity: a rewrite must not normalize a link style it was not
 * asked to touch.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HyperlinkReference extends Node
{
    public function __construct(
        ByteSpan $span,
        public string $text,
        public ?string $embeddedUri = null,
        public bool $anonymous = false,
        public bool $simple = false,
    ) {
        if ('' === $text) {
            throw new InvalidArgumentException('Hyperlink reference text must not be empty.');
        }

        if ($simple && null !== $embeddedUri) {
            throw new InvalidArgumentException('A simple hyperlink reference carries no embedded URI.');
        }

        parent::__construct($span);
    }
}
