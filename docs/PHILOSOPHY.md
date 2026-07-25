# Ikabud Philosophy

## The problem Ikabud exists to solve

Business software decays faster than it is built. A custom application works
well for the first team that builds it. Two years later, the original
programmer has moved on, the architecture has eroded, and the next team cannot
safely make changes without breaking something else.

The usual responses to this problem create new problems:

- **Frameworks** standardize structure but do not govern module boundaries.
  Any module can call any other module's code. Over time, dependencies tangle
  and the framework becomes the legacy.
- **CMS platforms** make content management easy but force business logic into
  plugin ecosystems that were never designed for multi-tenant isolation,
  cross-module workflows, or polyglot services.
- **Microservices** provide strong boundaries but at the cost of operational
  complexity that most organizations cannot sustain.

Ikabud exists at a different point in the design space: **a governed runtime
that enforces module boundaries, ownership, and contracts without requiring
a distributed systems infrastructure.**

---

## Kernel governance and module freedom

The central architectural decision in Ikabud is that the **kernel governs** and
**modules provide**. This separation is not cosmetic. It is enforced at runtime.

The kernel owns:

- Request lifecycle and routing
- Tenant resolution and isolation
- Authentication and authorization
- Capability dispatch (how modules call each other)
- Database access (which tables each module may touch)
- Rendering contracts (DiSyL components and entity views)
- Security headers, CSP, and CSRF enforcement

Modules own:

- Business logic and domain rules
- Their own database tables
- Their own templates and views
- Capabilities they expose to other modules
- Their own settings and configuration

This division means a module cannot bypass the kernel to access another
module's data. A module cannot read a table it has not declared. A module
cannot render output outside the platform's security policy.

**Why this matters:** When the original programmer leaves, the next developer
does not need to trust that everything was wired correctly. The kernel enforces
the boundaries. Module code can be understood in isolation because its
permissions are explicit and verified.

---

## Why boundaries are enforced at runtime, not just documented

Documented conventions are forgotten. Code review catches some violations but
not all. As teams grow and turnover occurs, undocumented dependencies
accumulate.

During module-owned runtime requests, the kernel enforces boundaries at the
database and dispatch layer:

- `KernelPDO` validates SQL queries against the module's declared
  `owns_tables` / `reads_tables`. Undeclared table access through the standard
  kernel path throws a `RuntimeException`. Privileged infrastructure
  (migrations, control-plane access, approved raw-SQL escapement) uses
  separate controlled paths with explicit review.
- The capability bus (`app()->capabilities()->call(...)`) is the primary
  cross-module integration path. Direct class imports across modules are
  forbidden by convention and detected by CI.
- Route dispatch sets explicit module context before any handler runs, cleared
  in a `finally` block. There is no ambiguity about which module owns the
  current request.

**The cost:** Modules cannot casually share data. Cross-module queries must go
through capability contracts, which means designing those contracts up front.
**The benefit:** A module's data and behavior remain predictable even as the
rest of the system changes around it.

---

## Why business systems must survive their original programmer

This principle underlies every major architectural decision in Ikabud.

A school district's guidance system, a bakery's production tracking, or a
warehouse's inventory management should outlive whoever wrote the first line
of code. Public institutions, small businesses, and non-profits cannot afford
to rewrite their operational software every time a developer leaves.

This constraint drives:

- **Explicit contracts over implicit coupling.** If a module relies on another
  module, the dependency is declared in its manifest and mediated by the
  capability bus. No hidden imports.
- **Fail-closed tenant behavior.** If a tenant database is unreachable, the
  system does not silently serve corrupted data or expose one tenant's data to
  another. It fails safe.
- **Stable public contracts.** Module manifests, capability IDs, and rendering
  components are compatibility-sensitive. Internal implementation can move,
  but the public surface stays stable. See [kernel-stable-contracts.md](kernel/kernel-stable-contracts.md).
- **Test discipline.** The repository maintains an extensive automated
  integration and contract test suite running against real databases, not
  mocks. Current test counts are reported by CI. Changes that pass CI are
  less likely to break in production.

---

## Why server-first still matters

Ikabud is designed **server-first**: the server renders pages, manages state,
enforces policy, and coordinates data. The browser enhances, but does not
replace, the server.

This is a deliberate choice.

Server-first means:

- Core business flows work without JavaScript. Forms submit, pages render,
  reports export. A user with a slow connection or an older device is not
  excluded.
- The server is the single source of truth for authorization. Every request is
  authenticated, every capability is checked, every query is validated against
  module table ownership. No client-side state can bypass these checks.
- Progressive enhancement is the default: start with a working HTML page, then
  add interactivity where it improves the experience.
- The learning curve for module developers is lower. They write PHP templates
  (DiSyL) and PHP handlers, not complex client-state management.

Server-first does not mean no client logic. The system supports:

- Alpine.js for interactive UI components
- HTMX for partial page updates
- A visual page builder (React/Vite) for CMS content
- Progressive hydration for interactive islands
- Mobile API endpoints with offline sync

But these are enhancements, not foundations. The foundation is a server that
renders correct, secure, working pages without them.

---

## Why DiSyL exists

DiSyL (Declarative Ikabud Syntax Language) is the rendering contract across
all modules, themes, and builders. It exists because most business applications
need a **consistent UI model** even when modules are built by different teams
at different times.

Alternatives considered and why they were not chosen:

| Approach | Problem |
|---|---|
| Each module uses its own template engine | Inconsistent patterns, no shared components |
| React/SPA everywhere | Operational complexity, JS dependency for every page |
| Twig/Blade | No kernel-native entity-view, capability, governance, or bridge contracts without additional application-specific infrastructure |
| Pure PHP templates | No safety, no governance, no cross-module consistency |

DiSyL provides:

- A component model with props, slots, and scoped styles
- Entity views that render any registered entity type with a single tag
- Server rendering with optional client hydration
- Capability-aware rendering (a component can check whether the current user
  has permission before rendering itself)
- Framework-neutral output (Alpine.js, HTMX, or custom bridges)

**The goal is not to create a new language for its own sake.** The goal is to
give the platform one rendering model that works across CMS pages, module UIs,
entity views, theme layers, and progressively enhanced interactive surfaces.
The alternative — each team choosing its own rendering stack — guarantees
architectural drift over time.

---

## Why PHP hosts the kernel

PHP is the host language for pragmatic reasons, not ideological ones.

- **Shared hosting compatibility.** Many of Ikabud's target users (schools,
  small businesses, non-profits) operate on shared hosting. PHP runs
  everywhere with minimal configuration.
- **Request-scoped architecture.** PHP's per-request lifecycle aligns with
  Ikabud's stateless kernel design. No long-running processes to manage.
- **Module ecosystem.** PHP has mature tooling for package management
  (Composer), testing (PHPUnit), and database access (PDO).

**This is not a PHP-only platform.** The capability bus and ServiceProxy
protocol allow modules in Python, Node.js, Go, Rust, or any language that
speaks HTTP+JSON. The kernel is PHP-hosted. Capabilities can live anywhere.

---

## Why polyglot capabilities matter

Not every business problem fits PHP well.

- Complex data analysis may benefit from Python's scientific stack.
- Real-time WebSocket handling may benefit from Node.js.
- High-throughput image processing may benefit from Go.
- Performance-critical numerical computation may benefit from Rust.

Ikabud does not force every capability into PHP. It provides a
language-agnostic dispatch protocol: any service that implements the
ServiceProxy contract (`POST /capability/call` with JSON payload) can
register as a module and participate in the capability bus.

**The trade-off:** Polyglot services increase operational complexity. They
require process management, health checking, and circuit breakers. Ikabud
manages these through the kernel's service infrastructure, but the operator
must deploy and maintain the external service.

---

## What "Kernel OS" means — and does not mean

**It means:** The kernel provides the governed runtime that modules depend on.
It is the fixed point in the architecture — the thing that stays consistent as
modules come and go.

**It does not mean:** Ikabud replaces Linux, a container runtime, or a
hypervisor. The term is an architectural metaphor. The kernel runs on PHP
under a standard web server.

The architectural metaphor is useful because it describes the relationship
accurately: the kernel owns policy, enforcement, and resource management;
modules own business behavior. Like an operating system kernel, the Ikabud
kernel is not useful in isolation — it exists to run modules.

---

## Why the "Hyperkernel" label was dropped

An earlier architectural phase explored the concept of a "Hyperkernel" — a
universal integration layer spanning multiple CMS platforms. As the project
matured, it became clear that this label overstated the project's scope and
created confusion.

Ikabud is not a multi-CMS platform. It is not a universal kernel for all
application software. It is a **governed business application platform** for
organizations that need module isolation, tenant safety, and explicit contracts
without the operational complexity of microservices.

Dropping "Hyperkernel" was a narrowing of claims, not a retreat. The current
positioning is more honest and more defensible. See [TERMINOLOGY.md](TERMINOLOGY.md)
for the full terminology record.

---

## The limits of Ikabud's architectural claims

Ikabud makes specific claims and explicitly does not make others.

### What Ikabud does well

- Multi-tenant business applications with isolated databases
- Module isolation with enforced table-level access control
- Cross-module capability contracts with versioning
- Server-rendered UIs with progressive enhancement
- Shared hosting compatibility
- Export pipeline (CSV, DOCX, PDF)
- Mobile API backend with offline sync
- Workflow engine with event-driven triggers
- AI governance with policy controls

### What Ikabud does not claim

- Replace a hardware operating system or hypervisor
- Replace Kubernetes or container orchestration
- Replace a dedicated CRM, ERP, or accounting system out of the box
- Compete with WordPress on plugin quantity
- Compete with Laravel on developer ergonomics
- Provide real-time collaboration (no WebSocket server)
- Provide native mobile applications (provides API backend only)

These limits are intentional. Ikabud targets the space between off-the-shelf
SaaS and custom development — where existing products do not fit the
organization's workflows and custom code needs governance.

---

## Why adoption comes before architectural purity

The architecture is designed to be adopted incrementally.

- A single module (like `contact-form` or `daily-ledger`) can be deployed on
  its own without enabling the full platform.
- Modules have independent migrations. An organization can adopt one capability
  at a time.
- The Compatibility database profile (MySQL 5.7, shared hosting) ensures the
  platform runs where organizations already host their data.
- The licensing model (open-core with MIT Community Edition) means core
  infrastructure is free. Organizations pay only for the enterprise features
  they need.

Architectural purity is a means, not an end. The goal is working software that
organizations can actually deploy, use, and maintain. If a design decision
makes the architecture cleaner but prevents a real organization from using the
software, the decision should be reconsidered.

---

## What Ikabud will not become

- **A mandatory SaaS platform.** The core Ikabud project is self-hostable and
  does not require a hosted Ikabud account or mandatory telemetry. Hosted
  deployments, managed services, and commercial support may be provided
  separately by the maintainer or third-party implementers.
- **A no-code platform.** Ikabud reduces the amount of code needed for common
  business patterns (entity views, workflows, reports), but module development
  requires programming.
- **A marketplace.** There is no app store, no revenue share, and no
  centralized distribution channel for modules. Modules are code in a
  repository.
- **A consultancy in itself.** Ikabud is a software platform, not a consulting
  engagement. Implementation, training, hosting, and support may be provided
  separately by the maintainer or third-party implementers.
- **A framework you build from scratch.** Ikabud is a platform you extend with
  modules. Starting from `bootstrap.php` and building everything yourself is
  possible but not recommended.

---

## Stewardship beyond the founder

Ikabud is currently maintained by its original creator. A healthy project
plans for continuity.

The governance model is designed to be transferred:

- Stable contracts ensure module behavior does not depend on a single
  maintainer's knowledge.
- Test coverage (the project maintains an extensive automated integration and
  contract test suite; current counts are reported by CI) means future
  maintainers can refactor with confidence.
- Documentation separates the "what" from the "why" to preserve design intent.
- The open-core licensing allows the community edition to survive independently
  of the commercial edition.

There is no formal governance structure yet. As the contributor base grows,
this document will be updated to reflect how decisions are made and who holds
which responsibilities.

---

## Meaning of the name

"Ikabud" is derived from "I know a buddy" — the idea that every organization
has someone who keeps the systems running, often informally, often without
budget or authority. Ikabud is designed for that person: the in-house
developer, the IT coordinator at a school, the operations lead at a
manufacturer who also manages the software.

The name is a reminder that the software should serve the people who keep
organizations going, not the other way around.

---

## Related documents

- [TERMINOLOGY.md](TERMINOLOGY.md) — canonical terminology and retired terms
- [docs/kernel/ARCHITECTURE.md](kernel/ARCHITECTURE.md) — system architecture
- [docs/kernel/kernel-stable-contracts.md](kernel/kernel-stable-contracts.md) — stability guarantees
- [docs/kernel/disyl-overview.md](kernel/disyl-overview.md) — DiSyL philosophy and design
- [CONTRIBUTING.md](../CONTRIBUTING.md) — how to contribute
- [LICENSING.md](../LICENSING.md) — open-core licensing model
