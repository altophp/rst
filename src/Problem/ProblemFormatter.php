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
 * Renders problems as plain text lines.
 *
 * Positions are byte offsets over the original input; line and column
 * display arrives once the source model can resolve them.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ProblemFormatter
{
    /**
     * One line per problem, each terminated by a newline.
     */
    public function format(ProblemReport $report): string
    {
        $text = '';

        foreach ($report as $problem) {
            $text .= $this->formatProblem($problem) . "\n";
        }

        return $text;
    }

    public function formatProblem(Problem $problem): string
    {
        $line = sprintf('%s [%s] %s', $problem->severity->value, $problem->code, $problem->message);

        if (null !== $problem->span) {
            $line .= sprintf(' (bytes %d..%d)', $problem->span->start, $problem->span->end());
        }

        return $line;
    }
}
