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

/**
 * Immutable compiled extension registry. Later extensions replace handlers
 * with the same normalized directive name.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ExtensionSet
{
    /**
     * @param list<Extension>                                                                                 $extensions
     * @param array<string, DirectiveHandler>                                                                 $directiveHandlers
     * @param array<string, RoleHandler>                                                                      $roleHandlers
     * @param array<string, \Alto\Rst\Lint\ContextRule|\Alto\Rst\Lint\DocumentRule|\Alto\Rst\Lint\SourceRule> $lintRules
     * @param array<string, FixPass>                                                                          $fixPasses
     * @param array<string, FormatterPass>                                                                    $formatterPasses
     * @param array<string, StatisticsProvider>                                                               $statisticsProviders
     */
    private function __construct(
        private array $extensions = [],
        private array $directiveHandlers = [],
        private array $roleHandlers = [],
        private array $lintRules = [],
        private array $fixPasses = [],
        private array $formatterPasses = [],
        private array $statisticsProviders = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function with(Extension $extension): self
    {
        $extensions = $this->extensions;
        $directiveHandlers = $this->directiveHandlers;
        $roleHandlers = $this->roleHandlers;
        $lintRules = $this->lintRules;
        $fixPasses = $this->fixPasses;
        $formatterPasses = $this->formatterPasses;
        $statisticsProviders = $this->statisticsProviders;
        $extensions[] = $extension;

        foreach ($extension->directiveHandlers() as $handler) {
            $directiveHandlers[strtolower($handler->name())] = $handler;
        }

        foreach ($extension->roleHandlers() as $handler) {
            $roleHandlers[strtolower($handler->name())] = $handler;
        }

        foreach ($extension->lintRules() as $rule) {
            $lintRules[$rule->code()] = $rule;
        }

        foreach ($extension->fixPasses() as $pass) {
            $fixPasses[strtolower($pass->name())] = $pass;
        }

        foreach ($extension->formatterPasses() as $pass) {
            $formatterPasses[strtolower($pass->name())] = $pass;
        }

        foreach ($extension->statisticsProviders() as $provider) {
            $statisticsProviders[strtolower($provider->name())] = $provider;
        }

        return new self(
            $extensions,
            $directiveHandlers,
            $roleHandlers,
            $lintRules,
            $fixPasses,
            $formatterPasses,
            $statisticsProviders,
        );
    }

    /**
     * @return list<Extension>
     */
    public function all(): array
    {
        return $this->extensions;
    }

    public function directiveHandler(string $name): ?DirectiveHandler
    {
        return $this->directiveHandlers[strtolower($name)] ?? null;
    }

    public function roleHandler(string $name): ?RoleHandler
    {
        return $this->roleHandlers[strtolower($name)] ?? null;
    }

    /**
     * @return list<\Alto\Rst\Lint\ContextRule|\Alto\Rst\Lint\DocumentRule|\Alto\Rst\Lint\SourceRule>
     */
    public function lintRules(): array
    {
        return array_values($this->lintRules);
    }

    /**
     * @return list<FixPass>
     */
    public function fixPasses(): array
    {
        return array_values($this->fixPasses);
    }

    /**
     * @return list<FormatterPass>
     */
    public function formatterPasses(): array
    {
        return array_values($this->formatterPasses);
    }

    /**
     * @return list<StatisticsProvider>
     */
    public function statisticsProviders(): array
    {
        return array_values($this->statisticsProviders);
    }
}
