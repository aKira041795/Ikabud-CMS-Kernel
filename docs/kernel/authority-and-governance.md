# Authority and governance

The governance census and auditors report contract drift. Runtime refusal is a separate request-dispatch responsibility.

## Dispatch enforcement

`executeModuleHandler()` calls `Ikabud\Kernel\Http\AuthorityDispatchGuard` before resolving or invoking the module handler. The guard invokes `AuthorityScopeResolver` at request time.

A module may declare a governed route in `module.json`:

```json
{
  "capabilities": {
    "routes": {
      "POST /api/example": {
        "capability": "example.write@1",
        "allowed_roles": ["admin"]
      }
    }
  }
}
```

String route declarations remain valid and can obtain `allowed_roles` (or `allow_roles`) from `capabilities.policy.<capability-id>`. A configured declaration denies roles outside that set. An undeclared route, missing tenant scope, malformed declaration, or absent role policy is treated as missing configuration and remains compatible with pre-port behavior.

Set `KERNEL_AUTHORITY_DISPATCH_ENFORCE=false` to disable the dispatch guard without deploying code. The documented default is **enabled** when the variable is absent. Disabling the guard is an operational recovery mechanism and should be temporary.

## Delegated declarations (`mode: module-decides`)

Modules whose authorization is capability-based — tenant-overridable minimums,
service-token capability allowlists — cannot be faithfully expressed as a role
allowlist. For those routes a module may declare that its own predicate stays the
authority:

```json
{
  "capabilities": {
    "routes": {
      "POST /api/example": {
        "mode": "module-decides",
        "capability": "example.capability.key"
      }
    }
  }
}
```

Semantics (chair ruling 2026-10-13, session `coverage-declaration-modes` in `.ai/consult/`):

- `mode` is explicit; role-mode declarations keep their semantics unchanged.
- `capability` is the **exact module authorization identifier** (the argument the
  module's own predicate receives). It describes the contract and must not
  independently decide authorization.
- `allowed_roles` is not permitted in delegated mode; a declaration carrying it is
  malformed and receives zero credit.
- The dispatch guard claims **no decision** for delegated routes: with no
  resolvable role set the route is permitted as not-configured — exactly as before
  the declaration. Delegation is not enforcement.
- GovernanceCensus reports delegated coverage **separately — it is never added to
  `dispatch_enforced`**. Per route: `delegated-bound` when the capability literal
  is reachable from this handler by statically resolvable named calls (same
  traversal shape as `reachesBus`), or `delegated-unbound` when nothing binds
  (declaration only, zero credit). Malformed delegations count in
  `delegated_invalid`.
- `delegated_verified` may only be claimed with **executable evidence** that the
  effective predicate gates the route — an enforcement test exercising the
  module's real decision path — not merely that the predicate call exists.
- Removing a delegated declaration must **not** change downstream authorization.
  If it does, the declaration has become an authorization authority and must be
  reverted.
- A kernel-invoked predicate adapter at dispatch requires proof of identical
  decisions and no duplicated authority, and is deliberately not part of this
  mode yet (promotion only after one independently verified delegated route).

## Template output bound

DiSyL limits rendered output to 5 MiB. All file-render branches and `renderString()` throw `TemplateOutputTooLargeException` when the bound is exceeded.

## Provenance

The authority/governance kernel files were copied from `/var/www/html/ikabudsix`. That repository and this repository have separate histories with no merge base; they must not be merged, rebased, or cherry-picked across one another.
