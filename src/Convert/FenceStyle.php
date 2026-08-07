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

/**
 * Which character fences a Markdown code block.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum FenceStyle: string
{
    case Backtick = '`';
    case Tilde = '~';

    public function fence(int $length = 3): string
    {
        return str_repeat($this->value, max(3, $length));
    }
}
