# SMS Notifications

SMS notification provider abstraction. Supports multiple SMS gateway providers with a unified capability interface.

## Providers

| Provider | Environment Variable |
|----------|-------------------|
| Semaphore | `SEMAPHORE_API_KEY` |
| Twilio | `TWILIO_*` |
| Vonage (Nexmo) | `VONAGE_*` |
| MoceanAPI | `MOCEAN_*` |

## Capabilities

| Capability | Purpose |
|-----------|---------|
| `sms.send@1` | Send an SMS notification |

## Callable by

`kernel`, `guidance`, `sms` (capability policy-restricted)

## Files

- Manifest: [`module.json`](module.json)
