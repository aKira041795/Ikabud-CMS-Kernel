# Project Audit Ledger (PAL)

Project costing, inventory, fabrication dues, sales, and audit management for construction, fabrication, signage, and installation businesses. ARK Workbench app. Auth-owned module — manages `pal_users` table with `admin` role.

## Features

- **Project management**: full project lifecycle with cost tracking
- **Job Order (JO) management**: quotation → approval → mobilization → execution → completion
- **Purchase Orders**: PO creation, supplier management, receiving
- **Material issuance**: track materials from inventory to project
- **Cash advances**: employee advance tracking and reconciliation
- **Receivables**: client billing, payments, aging reports
- **Approval workflows**: multi-step approval state machine
- **Mobilization**: track crew, equipment, and materials to job sites
- **Fabrication dues**: track fabrication progress and costs
- **Entity views**: 15+ entity view templates for list/detail rendering

## Key files

- Manifest: [`module.json`](module.json)
- Routes: [`routes.php`](routes.php)
- Handlers (22): [`handlers.php`](handlers.php)
- Services (18): [`src/Services/`](src/Services/)
- Migrations (21): [`migrations/`](migrations/)
- Templates: entity views in `templates/`

## Dependencies

None declared — standalone ARK Workbench app.
