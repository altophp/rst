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

use Alto\Rst\Node\Document;
use Alto\Rst\Problem\ProblemReport;
use Alto\Rst\Profile\Profile;
use Alto\Rst\Reference\ReferenceGraph;
use Alto\Rst\Source\Source;

/**
 * The outcome of a block parse: the document tree and every problem the
 * parser recovered from. Malformed input never throws; it lands here.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ParseResult
{
    private ?ReferenceGraph $references = null;

    private readonly Document $document;

    private readonly ProblemReport $problems;

    private readonly string $sourceBytes;

    private readonly ?Profile $profile;

    public function __construct(
        Document $document,
        ProblemReport $problems,
        ?Source $source = null,
        ?Profile $profile = null,
    ) {
        $this->document = $document;
        $this->problems = $problems;
        $this->sourceBytes = null === $source ? '' : $source->bytes;
        $this->profile = $profile;
    }

    public function document(): Document
    {
        return $this->document;
    }

    public function problems(): ProblemReport
    {
        return $this->problems;
    }

    /**
     * Whether this result was produced from these exact source bytes.
     */
    public function matchesSource(Source $source): bool
    {
        return $this->sourceBytes === $source->bytes;
    }

    /**
     * Document-wide definitions and resolved reference occurrences.
     *
     * Resolution problems remain separate from parser recovery problems
     * so enabling the graph does not change the established parse report.
     */
    public function references(): ReferenceGraph
    {
        return $this->references ??= ReferenceGraph::fromBytes(
            $this->document,
            $this->sourceBytes,
            $this->profile,
        );
    }
}
