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

namespace Alto\Rst\Extension\Symfony;

use Alto\Rst\Extension\AbstractExtension;
use Alto\Rst\Extension\DirectiveHandler;
use Alto\Rst\Extension\RoleHandler;
use Alto\Rst\Profile\DirectiveSpec;
use Alto\Rst\Profile\RoleSpec;

/**
 * Symfony documentation dialect capabilities.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SymfonyExtension extends AbstractExtension
{
    public function name(): string
    {
        return 'symfony';
    }

    public function directives(): array
    {
        return [
            new DirectiveSpec('configuration-block', false),
            new DirectiveSpec('best-practice', false),
            new DirectiveSpec('screencast', true),
        ];
    }

    public function roles(): array
    {
        return [
            new RoleSpec('namespace'),
            new RoleSpec('class'),
            new RoleSpec('method'),
            new RoleSpec('phpclass'),
            new RoleSpec('phpmethod'),
            new RoleSpec('phpfunction'),
        ];
    }

    /**
     * @return list<DirectiveHandler>
     */
    public function directiveHandlers(): array
    {
        return [
            new ConfigurationBlockHandler(),
            new ScreencastHandler(),
        ];
    }

    /**
     * @return list<RoleHandler>
     */
    public function roleHandlers(): array
    {
        return [
            new PhpSymbolRoleHandler('class'),
            new PhpSymbolRoleHandler('method'),
            new PhpSymbolRoleHandler('phpclass'),
            new PhpSymbolRoleHandler('phpmethod'),
            new PhpSymbolRoleHandler('phpfunction'),
        ];
    }
}
