# WMS Module (v1.4.0) - Comprehensive Summary

The Warehouse Management System (WMS) module is a reference-grade, entity-driven application running on the Ikabud Kernel OS. Designed to handle complex, high-volume transactions securely, it acts as an **ERP-lite** system isolated entirely within its own tenant database space without cross-contaminating public-facing modules.

---

## 1. Core Architecture & Safety Guarantees

*   **Movement-First Ledger**: The system enforces strict double-entry ledger rules. Physical stock levels (`wms_stocks`) are never directly mutated. Instead, an immutable `wms_movements` record is appended first, and stock levels act as a cached projection of these movements, ensuring a 100% reconstructable audit trail.
*   **Concurrency Safety**: During high-volume picking or reservations, stock queries utilize `SELECT ... FOR UPDATE` row-level locks, entirely preventing race conditions and negative stock errors.
*   **Idempotent Execution**: API endpoints handling inventory movements support `idempotency_key` verification, allowing the system to safely absorb and discard duplicate API calls (e.g., from network retries or double-clicks) without double-deducting stock.
*   **Traceable Reservations**: Any operation holding stock explicitly ties the reservation to a `reference_type` and `reference_id` (like an Order ID). This makes debugging allocation leaks impossible.

## 2. Execution & Warehouse Floor Operations

*   **Mobile-First Barcode Scanner App**: The module includes a standalone PWA shell (`/wms/scanner`) completely removing the desktop UI. It features a "Scan to Confirm" interface optimized for warehouse pickers on handhelds, with native wedge scanner/bluetooth gun support.
*   **Explicit Task Assignment**: Replaces implicit "go do this" work with a concrete `wms_tasks` queue. Warehouse staff are assigned explicit jobs (`pick`, `putaway`, `replenish`, `count`) prioritized globally on a live active-tasks dashboard.
*   **Optimized Pick Paths**: When generating pick-lists, the system automatically sorts the bins by their hierarchical `location_code`, forming the shortest physical walk path for warehouse operators.
*   **Reverse Logistics (Returns)**: A dedicated workflow with built-in quarantine isolation (`quarantine_location_id`) to hold returned goods until they are inspected and flagged as "good" for re-entry into sellable stock.

## 3. Intelligence & Optimization Layer

*   **Auto-Replenishment Engine**: Continuously monitors the warehouse against defined `reorder_point` and `safety_stock` levels. When breached, the system auto-generates `replenish` Tasks to move inventory from bulk reserve locations to active picking bins.
*   **Forecasting & Run-Rate Engine**: Analyzes 30-day trailing movement velocity to calculate a daily run rate, alerting staff via a "Days Remaining" metric before stockouts actually occur.
*   **Slotting Intelligence**: Identifies fast-moving items stored in difficult-to-access locations and suggests relocating them to prime bins to optimize picking speed.
*   **Flexible Picking Strategies**: Native support for **FIFO** (First-In, First-Out), **FEFO** (First-Expired, First-Out for perishable goods), and **LIFO**.

## 4. Production & Bakery Assembly (BOM)

*   **Recipe / Bill of Materials (BOM) Engine**: Define Finished Goods (e.g., Bread) and link them to required raw materials (e.g., Flour, Sugar, Yeast).
*   **Automated Factory Consumption**: Triggering a Production Order issues an assembly task to staff. Upon completion with the `actual_yield`, the WMS automatically deducts the exact raw ingredients consumed (`OUT` movements) and credits the newly minted Finished Good (`IN` movement).

## 5. Kernel OS Ecosystem Leverage

The WMS doesn't live in a silo; it aggressively leverages the Kernel OS capability bus for interoperability:
*   **Headless Stock Contracts**: Exposes `wms.stock.query@1`, `wms.stock.reserve@1`, and `wms.stock.release@1` so external Ecommerce or POS flows can validate stock, reserve it at sale time, and release it deterministically on cancellation.
*   **Order-Orchestration Contracts**: Exposes `wms.order.create@1` and `wms.order.cancel@1` so bridge-driven storefront flows can materialize a real WMS order, preserve the upstream `external_reference`, and close it cleanly if the source order is cancelled.
*   **Event-Driven Triggers**: Emits standard events like `wms.stock.low`, `wms.order.picked`, `wms.order.dispatched`, `wms.order.delivered`, `wms.order.payment_collected`, and `wms.production.completed`. These let Ecommerce, billing, and notification modules react automatically to warehouse activity without tight code coupling.
*   **Bridge-Friendly Cross-System Identity**: WMS order payloads and diagnostics are built around `external_reference` plus bridge metadata, which makes it practical to correlate an upstream ecommerce order, the downstream WMS order, reservation movements, and payment-collection follow-up from one identifier.

---

## 6. The Roadmap to Commercial Platformization

The WMS architecture has successfully crossed the threshold from an internal system into a highly stable **operational primitive**. It now possesses the correct foundational movement ledger, execution tools, and intelligence layers. 

The next phase deliberately pauses new "features" to prioritize transforming the module into a **Zero-Touch, Scalable Platform** ready for real-world enterprise adoption:

*   **Tenant Onboarding Engine (Day 0) (Completed)**: A streamlined setup wizard (`/wms/onboarding`) so businesses can initialize their environments seamlessly.
*   **Granular Configuration Layer (`wms_configs`) (Completed)**: Abstracted all hard-coded logic into tenant-level settings (handled via `wmsConfigGet()`/`wmsConfigSet()`). Admins will freely toggle picking strategies, negative stock policies, auto-replenishment behavior, and default quarantine routing.
*   **Observability & Debugging Tooling (Completed)**: Equipped administrators with internal diagnostics (`/wms/diagnostics`), including a **Movement Trace Viewer**, an active **Reservation Inspector**, and external-reference-aware order tracing for ecommerce bridge investigations.
*   **Financial Extension (Costing & POs) (Completed)**: Introduced the Financial & Business Layer computing live inventory valuation directly from the movement ledger using toggleable cost models (`FIFO`, `MAC`). Closed the outbound supply loop with a robust `wms_purchase_orders` workflow that translates replenishment forecasts securely into expected `wms_deliveries`.
*   **Ecosystem Orchestration via Contracts**: Publishing strict capability contracts (`wms.stock.query@1`, `wms.stock.reserve@1`, `wms.stock.release@1`, `wms.order.create@1`, `wms.order.cancel@1`) enabling external Point of Sale (POS) and Ecommerce modules to rely on the WMS as the definitive inventory and fulfillment authority.

For bridge-triggered reserve/release calls, WMS can also derive an active `location_id` from the provided warehouse. Tenants may pin this fallback with the `bridge.default_location_id` setting when integrations supply warehouse routing but omit an explicit location.

For bridge-triggered order flows, WMS stores the upstream order number in `external_reference` and emits `wms.order.payment_collected` after pay-on-delivery collection. That allows ecommerce to sync customer-visible payment state from the warehouse-side collection step instead of inferring payment completion from delivery alone.

By focusing on configuration, observability, and data portability (Import/Export), the WMS positions itself not merely as a tracking tool, but as a universally deployable ERP-lite engine capable of scaling horizontally across the Kernel OS ecosystem.
