# Security Policy

## Supported versions

Ikabud follows a rolling-release model. Security updates are provided for the
**latest stable release** of the Kernel OS. Older versions may receive critical
fixes on a best-effort basis.

| Version | Supported |
|---|---|
| 6.1.x (latest) | ✅ Active |
| 6.0.x | ⚠️ Critical fixes only |
| < 6.0 | ❌ Unsupported |

> Last reviewed: July 2026. This table is updated with each major and minor
> release. Consult the latest release notes for the current support matrix.

## Reporting a vulnerability

Ikabud handles authentication, tenant isolation, academic records, guidance
cases, warehouse operations, and public API endpoints. We take security
reports seriously.

### Private reporting

**Do not file a public GitHub issue for security vulnerabilities.**

Send details to: **noah2.omamalin@gmail.com**

Please include:

- A description of the vulnerability
- Steps to reproduce (proof of concept strongly preferred)
- Affected component(s) and version(s)
- Potential impact
- Any suggested mitigation or fix

### What to expect

These are good-faith targets rather than contractual service levels. Complex
vulnerabilities may require additional time.

| Step | Target response |
|---|---|
| Acknowledgment of receipt | Within 48 hours |
| Initial assessment and severity | Within 5 business days |
| Fix development (depending on severity) | 1–14 days |
| Coordinated disclosure release | After fix is deployed |

We will work with you to understand the issue, develop a fix, and coordinate
disclosure timing.

## Disclosure policy

We follow **coordinated disclosure**:

1. Reporter submits details privately
2. We confirm the vulnerability and develop a fix
3. Fix is applied to the supported release
4. Public advisory is published after the fix is available

We aim to publish advisories within 14 days of confirmation for critical
issues, or longer for complex fixes. These are good-faith targets rather
than contractual service levels.

## What not to post publicly

Until a fix is released and disclosed, do **not**:

- Post vulnerability details in public GitHub issues
- Discuss the vulnerability in public forums or social media
- Create proof-of-concept exploits in public repositories

## Scope

This policy covers:

- The Ikabud kernel (`kernel/`)
- Module runtime and manifest system
- Tenant isolation boundaries
- Authentication and authorization systems
- API endpoints and capability dispatch
- DiSyL template rendering

## Out of scope

The following are not covered by this policy:

- Vulnerabilities in deployed infrastructure (hosting, network, OS)
- Self-inflicted issues (misconfiguration, weak passwords)

### Third-party dependencies

Vulnerabilities originating in third-party dependencies (Composer packages,
npm modules, etc.) should preferably be reported to their respective
maintainers. Reports demonstrating an exploitable impact on Ikabud are still
accepted so that mitigation, upgrade guidance, or dependency pinning can be
coordinated.

## Bug bounty

Ikabud does not currently operate a bug bounty program. Security researchers
who report valid vulnerabilities will be credited in the release advisory
unless they prefer to remain anonymous.

## Automated security testing

The CI pipeline includes:

- PHP linting on every commit
- Tenant isolation hardening tests
- SQL injection protection via prepared statements (kernel-enforced)
- CSRF enforcement on browser-mutating routes

## Related documents

- [CONTRIBUTING.md](CONTRIBUTING.md) — general contribution guidelines
- [docs/kernel/contributor-workflows.md](docs/kernel/contributor-workflows.md) — technical setup and testing
- [docs/kernel/ARCHITECTURE.md](docs/kernel/ARCHITECTURE.md) — system architecture and security controls
