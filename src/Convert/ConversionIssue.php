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

namespace Alto\Rst\Convert;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Source\ByteSpan;

/**
 * One construct that did not survive conversion untouched.
 *
 * The construct key is a stable identifier, not prose: "directive:toctree",
 * "role:ref", "node:Table". Reports group on it, so it must not embed
 * positions or free text.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ConversionIssue
{
    public function __construct(
        public string $construct,
        public string $message,
        public IssueKind $kind = IssueKind::Unsupported,
        public ?ByteSpan $span = null,
    ) {
        if ('' === $construct) {
            throw new InvalidArgumentException('Conversion issue construct must not be empty.');
        }

        if ('' === $message) {
            throw new InvalidArgumentException('Conversion issue message must not be empty.');
        }
    }

    public static function unsupported(string $construct, string $message, ?ByteSpan $span = null): self
    {
        return new self($construct, $message, IssueKind::Unsupported, $span);
    }

    public static function lossy(string $construct, string $message, ?ByteSpan $span = null): self
    {
        return new self($construct, $message, IssueKind::Lossy, $span);
    }

    public static function approximated(string $construct, string $message, ?ByteSpan $span = null): self
    {
        return new self($construct, $message, IssueKind::Approximated, $span);
    }
}
