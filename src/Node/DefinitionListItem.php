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

use Alto\Rst\Source\ByteSpan;

/**
 * One definition-list item: an inline term, optional classifiers, and a
 * block-level definition.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DefinitionListItem extends ContainerNode
{
    /**
     * @param list<Text> $classifiers
     * @param list<Node> $definition
     */
    public function __construct(
        ByteSpan $span,
        public Text $term,
        public array $classifiers = [],
        private array $definition = [],
    ) {
        parent::__construct($span);
    }

    /**
     * @return list<Node>
     */
    public function children(): array
    {
        return [$this->term, ...$this->classifiers, ...$this->definition];
    }

    /**
     * @return list<Node>
     */
    public function definition(): array
    {
        return $this->definition;
    }
}
