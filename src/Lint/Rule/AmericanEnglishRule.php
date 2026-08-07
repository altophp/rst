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

namespace Alto\Rst\Lint\Rule;

use Alto\Rst\Lint\SourceRule;
use Alto\Rst\Node\Document;
use Alto\Rst\Problem\Problem;
use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Problem\ProblemSeverity;
use Alto\Rst\Source\ByteSpan;
use Alto\Rst\Source\Source;

/**
 * Checks that American English spellings are used, matching the word list
 * of the doctor-rst american_english rule.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class AmericanEnglishRule implements SourceRule
{
    /**
     * British spelling mapped to its American replacement.
     */
    private const array SPELLINGS = [
        'behaviour' => 'behavior',
        'initialise' => 'initialize',
        'normalise' => 'normalize',
        'organise' => 'organize',
        'recognise' => 'recognize',
        'centre' => 'center',
        'colour' => 'color',
        'flavour' => 'flavor',
        'licence' => 'license',
    ];

    public function code(): string
    {
        return 'lint/american-english';
    }

    public function check(Document $document, Source $source, ProblemCollector $problems): void
    {
        foreach ($source->lines() as $line) {
            if ($line->isBlank()) {
                continue;
            }

            $content = $source->slice($line->span);

            foreach (self::SPELLINGS as $british => $american) {
                if (false === preg_match_all(sprintf('/%s/i', $british), $content, $matches, \PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                foreach ($matches[0] as [$word, $offset]) {
                    $problems->add(new Problem(
                        ProblemSeverity::Info,
                        $this->code(),
                        sprintf('Use the American English "%s" instead of "%s".', $american, $word),
                        ByteSpan::of($line->span->start + $offset, \strlen($word)),
                    ));
                }
            }
        }
    }
}
