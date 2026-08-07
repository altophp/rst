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

namespace Alto\Rst\Node;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Source\ByteSpan;

/**
 * A titled section.
 *
 * The level is resolved by the parser: adornment styles rank by order of
 * first appearance, per document, starting at 1. The adornment character
 * and the overline flag preserve the original source style for edits.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Section extends ContainerNode
{
    /**
     * @param list<Node> $body
     */
    public function __construct(
        ByteSpan $span,
        public int $level,
        public Title $title,
        private array $body,
        public string $adornment,
        public bool $hasOverline,
    ) {
        if ($level < 1) {
            throw new InvalidArgumentException(sprintf('Section level must be >= 1, got %d.', $level));
        }

        if (1 !== strlen($adornment)) {
            throw new InvalidArgumentException(sprintf('Section adornment must be a single character, got "%s".', $adornment));
        }

        parent::__construct($span);
    }

    /**
     * @return list<Node>
     */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * @return list<Node>
     */
    public function children(): array
    {
        return [$this->title, ...$this->body];
    }
}
