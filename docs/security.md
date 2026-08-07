# Security

Alto treats every RST input as untrusted.

## Safe defaults

- HTML text and attributes are escaped.
- URL schemes pass through `HtmlPolicy`.
- Unknown constructs render as inert fallbacks.
- File-reading directives are disabled unless conversion receives an
  explicit root policy.
- Reference and substitution processing has bounded algorithms.
- The project reference map operates only on caller-supplied graphs.

## File-reading directives

No profile enables file reads. HTML rendering never reads files.

Markdown conversion can expand `include` when the caller creates
`FileAccessPolicy::rootedAt($root)` and passes it through
`ConversionOptions`. The policy:

- resolves both root-relative and source-relative paths;
- rejects URLs, null bytes, and traversal above the root;
- validates the real target, so a symlink cannot escape the root;
- rejects missing or unreadable files;
- detects cycles and limits include nesting;
- keeps include options disabled until their semantics are implemented.

The policy is explicit authority over the complete configured root. Choose
the narrowest root that contains the documentation and its include files.
Do not root it at a home directory or filesystem root in an application.

`literalinclude`, `csv-table :file:`, and `csv-table :url:` remain disabled
even when an include policy exists.

## Raw HTML authority

Raw HTML is separate from file access. `allowRawHtml` is false in every
default conversion option, including `ConversionOptions::symfony()`.

A trusted RST-to-Markdown migration can set `allowRawHtml: true`. This
enables only inline `.. raw:: html` bodies without directive options. It
does not enable file reads, network access, other raw formats, or raw HTML
in the HTML renderer.

Treat the resulting Markdown as trusted content. Its embedded HTML must pass
through the target platform's sanitization policy before serving it to
untrusted readers.

## URL policy

The HTML renderer checks link and image destinations before emitting them.
Disallowed destinations retain an empty URL rather than becoming executable
markup.

Use the default policy unless an application has a narrower scheme
allowlist. HTML rendering has no raw passthrough path.

## Reference expansion limits

Indirect hyperlink chains use iterative, memoized resolution. Substitution
expansion is memoized and limited to:

- 256 nested levels;
- 1 MiB of expanded replacement text.

Exceeding either limit produces `substitution/expansion-limit` and leaves the
occurrence unresolved.

## Caller responsibilities

Alto does not own:

- file discovery outside the explicit include policy;
- network retrieval;
- application authentication or authorization;
- backup and recovery policy for edited files;
- sanitization of HTML after an application modifies renderer output;
- authorization for `RstFile::save()` and `saveAs()` targets.

`RstFile` provides local atomic replacement, fingerprint-based conflict
detection, permission preservation, and symlink refusal. It does not decide
which paths an application is allowed to change.

Keep parser problems, graph problems, project-map problems, and conversion
issues visible in application logs or validation output.
