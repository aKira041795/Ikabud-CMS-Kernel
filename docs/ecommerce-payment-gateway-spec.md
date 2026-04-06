# Ecommerce Payment Gateway Integration Spec

## Overview

The ecommerce module supports pluggable payment gateways via an abstraction layer.
The first supported provider is **PayMongo** (Philippines) covering credit/debit cards,
GCash, Maya, and GrabPay.

When the gateway is set to `manual` (default), the existing COD / bank-transfer flow
is preserved unchanged.

---

## Architecture

```
checkout handler
  └─ ecPaymentGatewayCreateIntent()      # abstraction (70-payment-gateways.php)
       └─ _ecGatewayPaymongoCreateIntent()
            └─ ecPaymongoCreateIntent()   # HTTP client (71-gateway-paymongo.php)
```

### Files

| File | Purpose |
|------|---------|
| `helpers/70-payment-gateways.php` | Gateway-agnostic abstraction (create intent, verify, webhook) |
| `helpers/71-gateway-paymongo.php` | PayMongo REST API cURL client |
| `handlers/86-api-checkout.php` | Modified — branches on gateway after order creation |
| `handlers/87-payment-gateway.php` | Payment return page + PayMongo webhook endpoint |
| `database/migrations/010_ec_payment_intent_fields.sql` | Adds `payment_intent_id`, `client_key` to `ec_payment_transactions` |
| `routes.php` | New routes for return + webhook |
| `module.json` | New settings, migration ref, webhook event |

---

## Settings (module.json)

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `payment_gateway` | select | `manual` | Active gateway (`manual` or `paymongo`) |
| `payment_gateway_mode` | select | `sandbox` | `sandbox` or `live` |
| `paymongo_public_key` | text | — | PayMongo publishable key (`pk_test_…` / `pk_live_…`) |
| `paymongo_secret_key` | text | — | PayMongo secret key (`sk_test_…` / `sk_live_…`) |
| `paymongo_webhook_secret` | text | — | Webhook signing secret (from PayMongo dashboard) |
| `paymongo_allowed_methods` | text | `card,gcash,maya` | Comma-separated payment method types |

---

## Supported Payment Methods (PayMongo)

The `paymongo_allowed_methods` setting accepts a comma-separated list of payment methods to display on the PayMongo checkout page.

| Method ID    | Name                         | Description                               |
|--------------|------------------------------|-------------------------------------------|
| `card`       | Credit/Debit Cards           | Visa, Mastercard                          |
| `gcash`      | GCash                        | E-wallet                                  |
| `maya`       | Maya                         | E-wallet (formerly PayMaya)               |
| `grab_pay`   | GrabPay                      | E-wallet                                  |
| `dob`        | Direct Online Banking        | BPI, UnionBank (requires customer action) |
| `billease`   | BillEase                     | Buy Now, Pay Later                        |
| `qrph`       | QR Ph                        | Standardized QR payment in the PH         |

*Note: Some payment methods might require explicit activation within the PayMongo dashboard.*

---

## Flows

### 1. Checkout (card / e-wallet)

1. `POST /api/v1/ecommerce/checkout` creates the order.
2. If `payment_gateway !== 'manual'`, calls `ecPaymentGatewayCreateIntent()`.
3. PayMongo returns a Payment Intent with `checkout_url`.
4. Frontend redirects customer to `checkout_url` (3DS or e-wallet auth).
5. After completion, PayMongo redirects to `GET /ecommerce/payment/return`.

### 2. Payment Return

1. `ecPaymentReturn()` receives `order_id` + `token` query params.
2. Verifies token against order's `confirmation_token`.
3. Calls `ecPaymentGatewayVerify()` → `ecPaymongoRetrieveIntent()`.
4. If intent status is `succeeded`, calls `ecOrderMarkPaid()`.
5. Redirects to `/ecommerce/order/{token}` (confirmation page).

### 3. Webhook (async confirmation)

1. PayMongo sends `POST /api/v1/ecommerce/webhooks/paymongo`.
2. `ecPaymongoWebhook()` reads raw body + `Paymongo-Signature` header.
3. Calls `ecPaymentGatewayWebhookHandle()`:
   - Verifies HMAC-SHA256 signature.
   - Extracts `payment_intent_id` from event payload.
   - Looks up order via `ec_payment_transactions.payment_intent_id`.
   - Calls `ecOrderMarkPaid()` if status is `succeeded`.
4. Returns HTTP 200.

---

## Database Schema Addition

```sql
ALTER TABLE ec_payment_transactions
  ADD COLUMN payment_intent_id VARCHAR(255) DEFAULT NULL,
  ADD COLUMN client_key        VARCHAR(255) DEFAULT NULL;
CREATE INDEX idx_ec_payment_intent ON ec_payment_transactions(payment_intent_id);
```

---

## Testing

### Sandbox credentials

- Public key: `pk_test_…` (from PayMongo dashboard)
- Secret key: `sk_test_…`
- Test card: `4343 4343 4343 4345` (any future expiry, any CVC)
- GCash/Maya test: use sandbox test mobile numbers from PayMongo docs

### Manual test checklist

1. Set `payment_gateway = paymongo`, `payment_gateway_mode = sandbox`.
2. Add sandbox keys in ecommerce admin settings.
3. Add product to cart → checkout → verify redirect to PayMongo.
4. Complete test payment → verify redirect back → order marked as paid.
5. Verify webhook delivery in PayMongo dashboard → order marked paid.

---

## Adding a New Gateway

1. Create `helpers/71-gateway-{name}.php` implementing:
   - `ec{Name}CreateIntent($amountCentavos, $currency, $methods, $description)`
   - `ec{Name}RetrieveIntent($intentId)`
   - `ec{Name}VerifyWebhook($rawBody, $sigHeader, $webhookSecret)`
2. Add bridge functions in `70-payment-gateways.php`:
   - `_ecGateway{Name}CreateIntent()`
   - `_ecGateway{Name}Verify()`
   - `_ecGateway{Name}WebhookHandle()`
3. Add gateway option to the `payment_gateway` select in `module.json`.
4. Add webhook route in `routes.php`.
5. Register helper file in `helpers.php`.

---

## Security Notes

- Webhook signature verification is **mandatory** — unsigned payloads are rejected.
- Secret keys are stored per-tenant in module settings (never logged).
- The payment return handler validates `confirmation_token` to prevent order enumeration.
- `ecOrderMarkPaid()` is idempotent — duplicate webhook deliveries are safe.
