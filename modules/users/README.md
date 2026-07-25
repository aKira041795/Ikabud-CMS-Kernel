# Users

CMS user accounts and roles management. Shares the `cms_users` table with the CMS module. Auth-owned — `administrator` role.

## Features

- User CRUD (create, read, update, delete)
- Role-based access management
- Capability assignment per role

## Capabilities

| Capability | Purpose |
|-----------|---------|
| `users.get@1` | Get user details |
| `users.list@1` | List users with role/capability filtering |

## Files

- Manifest: [`module.json`](module.json)
