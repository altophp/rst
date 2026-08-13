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

namespace Alto\Rst\Lint;

use Alto\Rst\Lint\Rule\AmericanEnglishRule;
use Alto\Rst\Lint\Rule\BlankLineAfterAnchorRule;
use Alto\Rst\Lint\Rule\BlankLineAfterDirectiveRule;
use Alto\Rst\Lint\Rule\CodeBlockLanguageRule;
use Alto\Rst\Lint\Rule\CodeBlockTerminalRule;
use Alto\Rst\Lint\Rule\DuplicateTargetRule;
use Alto\Rst\Lint\Rule\EmptySectionRule;
use Alto\Rst\Lint\Rule\ForbiddenDirectiveRule;
use Alto\Rst\Lint\Rule\ForbiddenLinkDestinationRule;
use Alto\Rst\Lint\Rule\IndentationRule;
use Alto\Rst\Lint\Rule\InvalidLinkDestinationRule;
use Alto\Rst\Lint\Rule\MaxBlankLinesRule;
use Alto\Rst\Lint\Rule\MaxLineLengthRule;
use Alto\Rst\Lint\Rule\NoTabRule;
use Alto\Rst\Lint\Rule\SectionLevelJumpRule;
use Alto\Rst\Lint\Rule\TrailingWhitespaceRule;
use Alto\Rst\Lint\Rule\TransitionPlacementRule;
use Alto\Rst\Lint\Rule\UnresolvedReferenceRule;
use Alto\Rst\Lint\Rule\UnusedExternalLinkDefinitionRule;
use Alto\Rst\Lint\Rule\VersionDirectiveVersionRule;

/**
 * The immutable set of enabled lint rules, keyed by rule code.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class LintConfig
{
    /**
     * @param array<string, ContextRule|DocumentRule|SourceRule> $rules
     */
    private function __construct(
        private array $rules,
    ) {}

    /**
     * The built-in rule set, tree rules and source rules alike.
     */
    public static function recommended(): self
    {
        return new self([])
            ->withRule(new TransitionPlacementRule())
            ->withRule(new SectionLevelJumpRule())
            ->withRule(new DuplicateTargetRule())
            ->withRule(new EmptySectionRule())
            ->withRule(new ForbiddenDirectiveRule())
            ->withRule(new CodeBlockLanguageRule())
            ->withRule(new CodeBlockTerminalRule())
            ->withRule(new VersionDirectiveVersionRule())
            ->withRule(new BlankLineAfterDirectiveRule())
            ->withRule(new NoTabRule())
            ->withRule(new IndentationRule())
            ->withRule(new MaxLineLengthRule())
            ->withRule(new TrailingWhitespaceRule())
            ->withRule(new MaxBlankLinesRule())
            ->withRule(new AmericanEnglishRule())
            ->withRule(new UnresolvedReferenceRule())
            ->withRule(new UnusedExternalLinkDefinitionRule())
            ->withRule(new ForbiddenLinkDestinationRule())
            ->withRule(new InvalidLinkDestinationRule())
            ->withRule(new BlankLineAfterAnchorRule())
        ;
    }

    /**
     * Enables a rule; an already enabled rule with the same code is
     * replaced in place.
     */
    public function withRule(ContextRule|DocumentRule|SourceRule $rule): self
    {
        $rules = $this->rules;
        $rules[$rule->code()] = $rule;

        return new self($rules);
    }

    /**
     * Disables the rule with the given code; unknown codes are ignored.
     */
    public function withoutRule(string $code): self
    {
        $rules = $this->rules;
        unset($rules[$code]);

        return new self($rules);
    }

    /**
     * @return list<ContextRule|DocumentRule|SourceRule>
     */
    public function rules(): array
    {
        return array_values($this->rules);
    }
}
