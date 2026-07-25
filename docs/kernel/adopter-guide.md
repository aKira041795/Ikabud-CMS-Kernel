# Ikabud Adopter Guide

This guide helps technical decision-makers evaluate, deploy, and operate Ikabud
in a real organization. It supplements the [installation guide](installation.md)
with adoption-specific context.

---

## Before you start

Ikabud is a **modular business application platform** — not a CMS, not a
framework, and not a turnkey SaaS product. Adopting it means:

- You have in-house or contracted PHP development capability
- Your use case fits one or more existing modules, or you are prepared to
  build custom modules
- You operate or can provision a PHP/MySQL hosting environment
- You are comfortable with self-hosted, self-managed infrastructure

---

## Which module to begin with

Not all modules are equally mature. Start with a module that matches your
organizational need and has a proven deployment profile.

| Module | Maturity | Best for | Start here | Last reviewed |
|---|---|---|---|---|
| CMS | Production | Content-managed websites | ✅ | July 2026 |
| Daily Ledger | Production | Daily sales tracking | ✅ | July 2026 |
| Contact Form | Production | Lightweight inbound forms | ✅ | July 2026 |
| Media | Production | File uploads and library | ✅ | July 2026 |
| Users | Production | User accounts and roles | ✅ | July 2026 |
| Bakeshop | Controlled pilot | Bakery production tracking | ⚠️ | July 2026 |
| Guidance | Controlled pilot | School guidance counseling | ⚠️ | July 2026 |
| Ecommerce | Controlled pilot | Online storefront | ⚠️ | July 2026 |
| WMS | Prototype | Warehouse operations | ❌ | July 2026 |
| EHR | Prototype | Healthcare records | ❌ | July 2026 |
| AI | Prototype | AI-assisted features | ❌ | July 2026 |

> **Lifecycle definitions:**
> - **Production** — Used in real deployments, tested, documented, stable APIs
> - **Controlled pilot** — Functional but limited deployment history, may need
>   active support from maintainer
> - **Prototype** — Functional core but not yet used in unsupervised production
>
> Maturity assessments are dated and reflect the module's state at that date.
> Verify current status against the latest release notes before planning a
> deployment.

---

## Minimum viable deployment

The smallest useful Ikabud installation consists of:

1. **Kernel OS** — routing, tenancy, auth, database access
2. **Users module** — account and role management
3. **One business module** — the module that provides your actual functionality
4. **A theme** — ARK-based or custom, controls presentation

Example: A school starting with the Guidance module would deploy Kernel OS +
Users + Guidance + an ARK theme.

---

## Recommended pilot size

- **Start with a single tenant** (one organization/database)
- **Limit to 10–20 active users** during the pilot phase
- **Run for 4–8 weeks** before expanding to additional tenants or users
- **Test backup and restore procedures** during the pilot, not after

---

## Supported hosting

| Environment | Supported | Notes |
|---|---|---|
| Bluehost shared hosting | ✅ | Compatibility profile (MySQL 5.7, no CTEs/window functions) |
| Any shared hosting with PHP 8.1+ + MySQL 5.7+ | ✅ | May need PHP extension verification |
| VPS / dedicated server | ✅ | Both Compatibility and Enterprise profiles |
| Docker | ✅ | See `docker/` directory |
| Local development | ✅ | See [contributor-workflows.md](contributor-workflows.md) |

For Enterprise profile features (MySQL 8.0+ window functions, CTEs, JSON_TABLE):
see [database-profiles.md](database-profiles.md).

---

## Backup and restore

### What to back up

1. **Databases** — each tenant database individually + the control-plane database
2. **`storage/uploads/` directory** — uploaded media files (do not back up
   the entire `storage/` directory; compiled caches and logs are ephemeral)
3. **`.env` file** — contains database credentials and app key — **encrypt
   this backup separately**
4. **`config/` directory** — environment-specific configuration

### Important precautions

- **Do not commit backups** to the repository or expose them via the web root.
- **Encrypt backups at rest** — `.env` contains database credentials and the
   application key. Use `gpg` or an equivalent tool for secrets-containing
   archives.
- **Restrict filesystem permissions** — backup files should be readable only
   by the backup user.
- **Test restore periodically** — an untested backup is not a backup.
- **Define retention and off-site copies** appropriate to your recovery
   objectives.

### Recommended procedure

```bash
# Backup all databases
mysqldump --single-transaction ikabud_control > backup_control_$(date +%F).sql
mysqldump --single-transaction ikabud_tenant_1 > backup_tenant_1_$(date +%F).sql

# Backup configuration and secrets — encrypt and restrict access
tar -czf - .env config/ | gpg --symmetric --cipher-algo AES256 > config_backup_$(date +%F).tar.gz.gpg

# Backup uploaded media (not the full storage/ directory)
tar -czf uploads_backup_$(date +%F).tar.gz storage/uploads/
```

### Restore

1. Restore databases from SQL dumps
2. Extract file backup
3. Verify `.env` credentials match restored databases
4. Run `php ikabud migrate` if restoring to a newer kernel version
5. Clear APCu cache (restart PHP-FPM or use `apcu_clear_cache()`)
6. Test login and basic operations before restoring to production

---

## Upgrades

### Minor and patch upgrades (6.0.x → 6.1.x)

1. Backup databases and files
2. Replace kernel files via Git pull or archive extract
3. Run `php ikabud migrate`
4. Clear compiled template cache: delete `storage/cache/compiled/*`
5. Clear APCu: restart PHP-FPM
6. Run `php tests/` focused test suite
7. Verify key workflows

### Major upgrades (5.x → 6.x)

1. Review changelog and release notes in `docs/releases/`
2. Check for breaking changes in kernel contracts
3. Follow the same procedure as minor upgrades, with extra verification
4. Test on a staging environment before production

---

## Rollback

1. Restore databases from pre-upgrade backups
2. Restore kernel files from pre-upgrade backup
3. Restore `.env` from pre-upgrade backup
4. Clear APCu
5. Verify rollback with a test login

---

## Monitoring

| What to monitor | How |
|---|---|
| PHP errors | `storage/logs/error.log` |
| Application warnings | `storage/logs/app.log` |
| Database connectivity | Kernel health checks (superadmin API) |
| Module failures | Capability circuit breaker traces |
| Tenant isolation | Tenant chaos tests (run periodically) |
| Disk usage | Server-level monitoring |

---

## Data export and exit

Ikabud provides:

- **Per-module export** — CSV, DOCX, PDF via the export pipeline
- **Direct database access** — All data is in standard MySQL tables with no
  encryption-at-rest in the application layer (except password hashes and
  encrypted DB credentials)
- **Media files** — standard files in `storage/` or configured upload directory

To fully export your data for migration:

1. Dump each tenant database: `mysqldump --single-transaction <tenant_db>`
2. Export media files: `tar -czf media_backup.tar.gz storage/uploads/` (or
   configured upload path)
3. Export configuration: `tar -czf config_backup.tar.gz config/ .env`

Ikabud avoids proprietary database formats and supports direct SQL export.
Migration may still require mapping module schemas, workflow state, capability
semantics, file references, and tenant boundaries to the destination system.
The platform's value is in the runtime and module capabilities, not in a
proprietary data format.

---

## Telemetry and data collection

Ikabud **collects no telemetry**. There are no phone-home calls, no analytics
tracking, no usage reporting, and no mandatory updates.

Application logs are written to local files only. No logs are sent externally.

---

## Service-level expectations

As an open-source project, Ikabud is provided without formal SLA. The
maintainer aims to meet the following good-faith targets:

- Respond to critical security issues within 48 hours
- Release patch fixes within 14 days for confirmed vulnerabilities
- Review pull requests within 5 business days
- Answer questions via GitHub Issues on a best-effort basis

These are good-faith targets rather than contractual service levels. Complex
vulnerabilities or changes may require additional time.

Production deployments should budget for internal or contracted support.

---

## Known limitations

- **No real-time collaboration** — No WebSocket server; pages use
  request-response cycle with optional Alpine.js enhancements
- **No native mobile apps** — API backend only; mobile apps require separate
  development
- **PHP process limit** — Each request is a new PHP process; long-running
  operations should use the job queue or workflow engine
- **MySQL 5.7 ceiling** (Compatibility profile) — window functions, CTEs, and
  JSON_TABLE are unavailable on shared hosting
- **No mandatory SaaS account** — Self-hostable; no hosted account required
   to use the software

---

## Related documents

- [installation.md](installation.md) — Technical installation and deployment
- [ARCHITECTURE.md](ARCHITECTURE.md) — System architecture
- [database-profiles.md](database-profiles.md) — Database compatibility profiles
- [../PHILOSOPHY.md](../PHILOSOPHY.md) — Project principles and design rationale
- [../../CONTRIBUTING.md](../../CONTRIBUTING.md) — Contributing and support
- [../../SECURITY.md](../../SECURITY.md) — Security policies
