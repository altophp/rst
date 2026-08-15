# Contributing to Alto RST

Contributions should preserve public contracts, source positions, conservative
recovery, and safe defaults.

## Prepare a checkout

```bash
composer install
composer qa
```

`composer qa` runs PHPStan, the PHP CS Fixer check, and PHPUnit. Run coverage
separately when a change affects executable code:

```bash
composer coverage
```

The coverage command enforces the repository's 97 percent line floor.

## Propose a change

Add or update tests for observable behavior. Update `docs/` and `CHANGELOG.md`
when the public contract changes. Keep parser recovery, source spans,
conversion fidelity, file authority, and reference diagnostics explicit.

Open a pull request against `main` only after the complete quality gate passes.
Describe the user-visible result, compatibility impact, and any security or
conversion tradeoff.
