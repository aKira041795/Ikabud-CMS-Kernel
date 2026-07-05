# Ticketing Module

## Purpose

`modules/ticketing` provides issue/request tracking with both authenticated staff workflows and public submission entry points.

## Core capabilities

Exposed capabilities:

- `ticketing.create@1`
- `entity.list.ticket@1`
- `entity.get.ticket@1`

Dependencies:

- `sms.send@1`

## Data ownership

Owned tables:

- `tickets`
- `ticket_comments`
- `ticket_attachments`
- `ticketing_settings`

Reads:

- `users`

## Runtime surface

Key pages and APIs:

- `/tickets` (list)
- `/tickets/create`
- `/tickets/{id}`
- `/submit-ticket` (public)
- `/api/v1/tickets/*`
- `/api/v1/tickets/public-submit`

## Entity-view adoption

Ticketing now provides DiSyL entity-view contracts at:

- `modules/ticketing/helpers/views/ticket.disyl`

Views:

- `ticket.table`
- `ticket.compact`
- `ticket.detailed`

Handlers for entity rendering consumers:

- `ticketing_cap_entity_list_ticket_1`
- `ticketing_cap_entity_get_ticket_1`

## Integration notes

- View configs are loaded at module bootstrap in `handlers.php` via `TemplateEngine::loadViewConfigs`.
- Entity rows include normalized display fields (`creator_name`, `assignee_name`, status/priority/category/source timestamps) for CMS/entity-list consumers.

## Test priorities

1. Capability contract checks for `entity.list.ticket@1` and `entity.get.ticket@1`.
2. CMS entity-list rendering integration for ticket table/compact contracts.
3. Public submit + attachment flow regressions.