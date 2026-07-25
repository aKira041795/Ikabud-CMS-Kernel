# Anti-Spam

Simple anti-spam protection layer for public-facing forms. Uses multiple lightweight techniques that require no external API dependencies.

## Techniques

- **Honeypot fields** — invisible form fields that trap bots
- **Rate limiting** — per-IP and per-session request caps
- **Keyword blocklist** — reject submissions containing known spam patterns
- **IP blocking** — manual and automatic IP blocklist management

## Capabilities

| Capability | Purpose |
|-----------|---------|
| `antispam.check@1` | Check a submission against all anti-spam rules |

## Integration

Other modules call `antispam.check@1` before processing form submissions. Security module reads `antispam_*` tables for auto-escalation.

## Files

- Manifest: [`module.json`](module.json)
- Handlers: [`handlers.php`](handlers.php)
