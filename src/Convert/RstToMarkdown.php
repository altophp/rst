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

use Alto\Rst\Convert\Writer\MarkdownWriter;
use Alto\Rst\Node\Document;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Reference\ProjectReferenceMap;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Source\Source;

/**
 * Translates a parsed reStructuredText document to GitHub-flavored
 * Markdown.
 *
 * The mapping is rule-driven and reference-preserving: reference-style RST
 * links stay reference-style Markdown links, embedded-URI links stay
 * inline, admonitions become GitHub alerts, and code-block languages map
 * through an explicit table. Conversion never throws on constructs it
 * cannot express; each one degrades conservatively and lands in the
 * report.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class RstToMarkdown
{
    public function convert(
        Document $document,
        Source $source,
        ?Profile $profile = null,
        ?ConversionOptions $options = null,
        ?ReferenceGraph $references = null,
        ?ProjectReferenceMap $projectReferences = null,
        ?string $sourcePath = null,
    ): ConversionResult {
        $effectiveProfile = $profile ?? Profile::docutils();
        $writer = new MarkdownWriter(
            $document,
            $source,
            $effectiveProfile,
            $options ?? new ConversionOptions(),
            null,
            $references ?? ReferenceGraph::fromDocument($document, $source, $effectiveProfile),
            $projectReferences,
            $sourcePath,
        );

        return $writer->write();
    }
}
