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

use Alto\Rst\Source\Source;

/**
 * Reads a deliberately small CommonMark subset into the Markdown model: the
 * only Markdown this engine must read is Markdown it could have written,
 * plus the constructs found in real documentation. See
 * _dev/knowledge/CONTRACTS-PHASE-2.md for the exact scope.
 *
 * read() never throws on malformed input: anything unrecognised degrades to
 * text or to a paragraph. It reports no problems, because the conversion
 * report is built by the converter, not the reader.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MarkdownReader
{
    public function read(string $markdown): MdDocument
    {
        $source = Source::fromString($markdown);
        $draft = new MarkdownBlockParser($source)->parseDocument();
        $inline = new MarkdownInlineParser($draft->definitionsByLabel());

        return $draft->finalize($inline);
    }
}
