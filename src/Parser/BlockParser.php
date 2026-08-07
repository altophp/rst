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

namespace Alto\Rst\Parser;

use Alto\Rst\Problem\ProblemCollector;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Source\Source;

/**
 * The block structure pass: turns a Source into the document tree.
 *
 * Inline content stays raw Text nodes; the inline pass arrives in a later
 * phase. Error recovery mirrors docutils: malformed constructs degrade to
 * reported problems with source positions, never exceptions.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class BlockParser
{
    public function parse(Source $source, ?Profile $profile = null): ParseResult
    {
        $profile ??= Profile::docutils();
        $collector = new ProblemCollector();
        $document = new BlockScanner($source, $collector, $profile)->parseDocument();

        return new ParseResult($document, $collector->report(), $source, $profile);
    }
}
