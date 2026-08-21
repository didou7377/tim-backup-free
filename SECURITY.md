# Security Policy

## Supported versions

TIM Backup is in initial development. Only the latest published release receives
security fixes.

## Reporting a vulnerability

Do not open a public GitHub issue for a suspected vulnerability.

Use GitHub's private vulnerability reporting feature for
`didou7377/tim-backup-free`. Include:

- A concise description and potential impact.
- Affected TIM Backup, WordPress, PHP, and WooCommerce versions.
- Reproduction steps or a minimal proof of concept.
- Any suggested mitigation.

Please do not access, modify, or retain data that does not belong to you. Allow a
reasonable remediation period before public disclosure.

## Project security principles

- Administrator capability and nonce checks for every state-changing action.
- Strict validation of archive paths, identifiers, and database names.
- No `eval`, dynamic PHP execution, telemetry, or remote executable code.
- Random archive identifiers, signed manifests, and SHA-256 payload hashes.
- No symbolic-link traversal during backup or restore.
- No secrets, credentials, customer data, or production archives in Git.

No software can guarantee the absence of vulnerabilities. Security reports are
investigated and addressed according to their impact.
