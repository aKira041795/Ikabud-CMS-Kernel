# ServiceProxy v2 and Capability Authorization Registry

> **Status:** Delivered in the Kernel 6.2 proof-program batch

---

## Scope

This note covers two additive trust-boundary controls:
- **ServiceProxy v2** — signed service-module requests with replay resistance and fail-closed verification
- **CapabilityAuthorizationRegistry** — persisted, versioned, default-deny authorization policy selection for governed capabilities

Code:
- `kernel/Capabilities/ServiceProxyV2.php`
- `kernel/Capabilities/CapabilityAuthorizationRegistry.php`
- `kernel/Capabilities/CapabilityBus.php`
- `kernel/Capabilities/ServiceProxy.php`

Tests:
- `tests/service_proxy_v2_test.php`
- `tests/capability_authorization_registry_test.php`

Migrations:
- `database/migrations/024_kernel_service_proxy_v2_nonce.sql`
- `database/migrations/025_kernel_capability_authorization_policies.sql`

---

## ServiceProxy v2 Envelope

### Signed header set

The v2 envelope signs a fixed header set:
- `method`
- `path`
- `host`
- `body_hash`
- `timestamp`
- `nonce`
- `kid`
- `alg`
- `endpoint`
- `provider`
- `capability`
- `version`

### Canonicalization and algorithms

`ServiceProxyV2` signs canonical JSON with:
- sorted keys
- no insignificant whitespace
- UTF-8 output
- no trailing newline

Allowed algorithms:
- `RS256`
- `ES256`

Anything else is rejected.

### Verification rules

Verification binds the request to:
- body hash
- endpoint
- provider
- capability
- version

Additional rules:
- timestamp skew must be `<= 300s`
- `kid` must resolve in the key ring
- duplicate nonce replay is rejected
- key-rotation overlap is supported
- storage or key lookup failure is **fail-closed**

### Nonce reservation

Replay resistance uses atomic nonce reservation in `nonce_reservations` from `database/migrations/024_kernel_service_proxy_v2_nonce.sql`.

---

## Capability Authorization Registry

`CapabilityAuthorizationRegistry` moves governed authorization decisions into a persisted policy table:
- table: `capability_authorization_policies`
- migration: `database/migrations/025_kernel_capability_authorization_policies.sql`
- seeded proof policy: `proof_lane.ping@1`, policy version `2`

### Delivered properties

- **default-deny** when no active policy allows the call
- **versioned policy selection**
- **cache + `invalidate()`** support
- **fail-closed** when the DB/policy registry is unavailable
- **audit logging** via `capability.authz.decision`
- **additive CapabilityBus wiring**

The registry does not replace all legacy v1 capability handling in one step; unregistered v1 capabilities remain unchanged by additive design.

---

## v1 / v2 Interaction Rule

The legacy `ServiceProxy` path must **not silently downgrade** a capability that requires protocol v2.

Delivered rule:
- if a capability is marked as requiring v2, the v1 proxy path refuses it
- legacy unregistered v1 capabilities remain on their existing path

This preserves backward compatibility without weakening a v2-required boundary.

---

## Verification Pointers

- Envelope signing, canonical JSON, binding, skew, replay, outage, and key-rotation cases: `tests/service_proxy_v2_test.php`
- Registry policy selection, default-deny, cache invalidation, DB fail-closed behavior, audit path, and no-silent-downgrade behavior: `tests/capability_authorization_registry_test.php`
