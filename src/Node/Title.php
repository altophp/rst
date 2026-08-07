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
 * A section title. The content stays a raw Text placeholder until the
 * inline pass exists.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Title extends ContainerNode
{
    public function __construct(
        ByteSpan $span,
        public Text $text,
    ) {
        parent::__construct($span);
    }

    /**
     * @return list<Text>
     */
    public function children(): array
    {
        return [$this->text];
    }
}
