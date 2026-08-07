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

namespace Alto\Rst\Convert\Markdown;

use Alto\Rst\Source\ByteSpan;

/**
 * A backtick-delimited code span.
 *
 * $text is the content after the CommonMark backtick-run matching rule: a
 * single leading and trailing space is stripped when the content both
 * starts and ends with a space and is not all spaces. $backtickCount is the
 * length of the delimiting backtick run, kept so the reverse direction can
 * pick a run longer than any backtick sequence inside the content.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MdCode extends MdNode
{
    public function __construct(
        ByteSpan $span,
        public string $text,
        public int $backtickCount,
    ) {
        parent::__construct($span);
    }
}
