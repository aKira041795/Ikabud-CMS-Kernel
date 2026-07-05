# Moodle SSO Validation Contract Gap — Follow-up

## Context

The system review item TD-M6 flagged a gap between the intended SSO validation contract and test coverage.
Current tests prove route and interface presence, but they do not assert full inbound-token validation behavior.

## Investigation Summary

- Verified contract shape in `ProviderAuthAdapterInterface`:
  - `buildLaunchUrl(array $user, array $resource): ?string`
  - `validateInboundToken(string $token, int $tenantId): ?array`
- Verified implementation in `SSOService::validateInboundToken`:
  - JWT shape check
  - `alg=HS256` check
  - expiry/issuer/audience checks
  - HMAC signature verification
  - single-use token consume via `moodleIntegrationConsumeSsoTokenForTenant`
- Verified integration test coverage in `tests/moodle_integration_module_test.php`:
  - asserts method existence
  - asserts unconfigured launch returns null
  - does not assert successful inbound validation path
  - does not assert failure modes (expired token, bad signature, wrong audience/issuer, replay)

## Gap Statement

The validation contract is implemented but under-tested at integration level.
This is a test contract gap, not a confirmed runtime bug.

## Follow-up Work Items

1. Add focused tests for `validateInboundToken` success path with a valid signed token.
2. Add failure-path tests for: expired token, bad signature, bad issuer/audience, malformed JWT.
3. Add replay protection test: token can be consumed once only.
4. Assert return payload shape: `user_id`, `learning_resource_id`, `tenant_id`.

## Suggested Target File

- `tests/moodle_sso_validation_contract_test.php`

## Priority

- Priority: Medium
- Owner: Integration team
- Target cycle: 6.2