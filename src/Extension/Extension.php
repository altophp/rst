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

namespace Alto\Rst\Extension;

use Alto\Rst\Lint\ContextRule;
use Alto\Rst\Lint\DocumentRule;
use Alto\Rst\Lint\SourceRule;
use Alto\Rst\Profile\DirectiveSpec;
use Alto\Rst\Profile\RoleSpec;

/**
 * A named bundle of profile capabilities and their executable handlers.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface Extension
{
    public function name(): string;

    /**
     * @return list<DirectiveSpec>
     */
    public function directives(): array;

    /**
     * @return list<RoleSpec>
     */
    public function roles(): array;

    /**
     * @return list<DirectiveHandler>
     */
    public function directiveHandlers(): array;

    /**
     * @return list<RoleHandler>
     */
    public function roleHandlers(): array;

    /**
     * @return list<ContextRule|DocumentRule|SourceRule>
     */
    public function lintRules(): array;

    /**
     * @return list<FixPass>
     */
    public function fixPasses(): array;

    /**
     * @return list<FormatterPass>
     */
    public function formatterPasses(): array;

    /**
     * @return list<StatisticsProvider>
     */
    public function statisticsProviders(): array;
}
