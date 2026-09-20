# Coding Standards

This document captures the current implementation standards that matter most when changing Metis.

## General Principles

- Extend existing runtime systems before adding new ones.
- Prefer a single clear ownership point for each behavior.
- Fail closed on authorization, routing, and input validation.
- Keep request paths lightweight and move expensive work into jobs or queued operations when practical.

## Request Handling

- Route new HTTP behavior through `system/src/Metis/Core/Routing/RouterRuntime.php` or validated manifest routes.
- Route new browser/API action posts through the AJAX controller registry when they fit the AJAX surface.
- Do not add live wrapper entrypoints for AJAX, cron, or webhooks.

## Security

- Every public endpoint needs an explicit authorization model.
- Browser-initiated state changes must have CSRF protection.
- Public or sensitive endpoints must declare rate limits explicitly or inherit them from a reviewed policy path.
- AJAX controllers should declare:
  - `module`
  - `permission`
  - `nonce_action`
  - `allow_additional_fields`
  - `schema`

Default to `allow_additional_fields => false` when the request shape is known.

## Validation

- Validate at the boundary, not deep inside downstream services when possible.
- Reject undeclared request fields on stable request shapes.
- Use normalized scalar types in controller schemas:
  - `string`
  - `integer`
  - `boolean`
  - `json`
  - `array`

## Services

- Reuse shared services from `Application::service()` or service registry helpers before adding new utilities.
- Keep service responsibilities narrow and composable.
- Avoid duplicating module lookup, route inference, release handling, or permission logic.

## Modules

- Module manifests must remain valid under `ModuleValidator`.
- Keep module bootstrap files minimal.
- Prefer service files and registered listeners over large bootstrap side effects.
- Do not duplicate runtime helpers that already exist in core.

## Events

- Use the event bus for cross-cutting reactions that should not create tight module coupling.
- Event names should stay stable, lowercase, and structured.
- Listeners should tolerate partial failure and not assume execution order unless priority is required.

## Performance

- Avoid repeated scans or repeated route/controller recomputation inside hot request paths.
- Prefer cached inventories and aggregated queries.
- Do not block user-facing requests on release, import, export, reporting, or webhook-heavy work when it can be queued.

## Error Handling

- Return consistent JSON for API and AJAX failures.
- Preserve safe public error messages and keep sensitive detail in logs.
- When hardening update/install paths, prefer staged writes and atomic promotion over live in-place mutation.

## Testing

- New hardening work should add or extend contract tests.
- Prefer small focused runtime/contract coverage before broad speculative testing.
- If a new endpoint or controller is added, update inventory tests where applicable.

## Documentation

- Update developer docs when architecture or extension seams materially change.
- Do not leave core behavior discoverable only by reading source.
