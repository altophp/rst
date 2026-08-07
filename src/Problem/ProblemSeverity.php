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

namespace Alto\Rst\Problem;

/**
 * Problem severity, mirroring the docutils system message levels.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum ProblemSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Severe = 'severe';

    /**
     * The docutils numeric level (1 to 4).
     */
    public function level(): int
    {
        return match ($this) {
            self::Info => 1,
            self::Warning => 2,
            self::Error => 3,
            self::Severe => 4,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->level() >= $other->level();
    }
}
