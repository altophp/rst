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
 * The generic form of any directive: name, arguments, options in source
 * order, the raw body span, and typed block children when its profile
 * declares ordinary RST block content. Literal and opaque bodies keep their
 * exact source span without inventing structure.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Directive extends ContainerNode
{
    /**
     * @param list<string>          $arguments
     * @param array<string, string> $options
     * @param list<Node>            $body
     */
    public function __construct(
        ByteSpan $span,
        public string $name,
        public array $arguments = [],
        public array $options = [],
        public ?ByteSpan $rawBody = null,
        public DirectiveBodyKind $bodyKind = DirectiveBodyKind::Opaque,
        private array $body = [],
    ) {
        if ('' === $name) {
            throw new InvalidArgumentException('Directive name must not be empty.');
        }

        if (DirectiveBodyKind::Blocks !== $bodyKind && [] !== $body) {
            throw new InvalidArgumentException('Only block directive bodies may contain child nodes.');
        }

        parent::__construct($span);
    }

    /**
     * @return list<Node>
     */
    public function children(): array
    {
        return $this->body;
    }
}
