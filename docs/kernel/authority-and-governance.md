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

## Template output bound

DiSyL limits rendered output to 5 MiB. All file-render branches and `renderString()` throw `TemplateOutputTooLargeException` when the bound is exceeded.

## Provenance

The authority/governance kernel files were copied from `/var/www/html/ikabudsix`. That repository and this repository have separate histories with no merge base; they must not be merged, rebased, or cherry-picked across one another.
