# Rules

The recommended policy contains twenty rules grouped by the evidence they
inspect. Configure the few rules with project-specific values rather than
forking the complete set.

## Structure

- `lint/transition-placement`
- `lint/section-level-jump`
- `lint/duplicate-target`
- `lint/empty-section`
- `lint/forbidden-directive`
- `lint/code-block-language`
- `lint/code-block-terminal`
- `lint/version-directive-version`

## Source

- `lint/blank-line-after-directive`
- `lint/no-tab`
- `lint/indentation`
- `lint/max-line-length`
- `lint/trailing-whitespace`
- `lint/max-blank-lines`
- `lint/american-english`
- `lint/blank-line-after-anchor`

## References

- `lint/unresolved-reference`
- `lint/unused-external-link-definition`
- `lint/forbidden-link-destination`
- `lint/invalid-link-destination`

Rules report stable codes and original byte spans. Reference-based negative
findings, such as an unused definition, are suppressed when opaque directive
content makes graph coverage incomplete.

Use [Configuration](configuration.md) to replace policy values and
[Custom rules](custom.md) for application-specific checks.
