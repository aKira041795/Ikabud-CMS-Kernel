# Security

Global security monitoring module. Provides file integrity monitoring, admin IP allowlisting, security audit logging, and auto-escalation from anti-spam events.

## Features

- **File integrity monitoring**: checksum-based detection of file changes
- **Admin IP allowlist**: restrict admin access to trusted IP ranges
- **Security audit logging**: structured security event log with search
- **Auto-escalation**: reads `antispam_*` tables and escalates suspicious activity

## Capabilities

| Capability | Purpose |
|-----------|---------|
| `security.audit@1` | Query security audit log |

## Files

- Manifest: [`module.json`](module.json)
