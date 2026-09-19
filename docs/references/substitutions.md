# Substitutions

Substitution definitions support replacement text, Unicode values, and images.
Expansion follows nested substitutions and reports cycles or missing names.

The resolver:

- ignores escaped pipes and inline literals;
- preserves linked `|name|_` and `|name|__` forms;
- memoizes expansions;
- stops after 256 nested levels;
- stops after 1 MiB of expanded text.

Exceeding a bound produces `substitution/expansion-limit` and leaves the
occurrence unresolved. These limits protect consumers from exponential
replacement graphs.

Literal directive bodies are skipped. An opaque directive body makes reference
coverage incomplete, so negative conclusions such as “unused definition” are
suppressed. Check `isCoverageComplete()` before making such a conclusion.
