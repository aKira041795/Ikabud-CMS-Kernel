# Guidance SMS Addon

SMS notification addon for the Guidance module. Sends appointment reminders, case updates, and booking confirmations via the kernel SMS capability.

## Capabilities

| Capability | Purpose |
|-----------|---------|
| `guidance_sms.send@1` | Send SMS notifications for guidance events |

## Dependencies

- `guidance` — hooks into guidance events
- `sms` — SMS provider abstraction

## Files

- Manifest: [`module.json`](module.json)
