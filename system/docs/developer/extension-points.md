# Extension Points

This document describes the supported seams third-party or sibling modules should build against.

## Module Manifest

The primary extension contract is the module manifest:

- `module.json`

Validation is enforced by:

- `system/src/Metis/Core/Modules/ModuleValidator.php`

Important manifest areas include:

- `slug`
- `name`
- `version`
- `description`
- `category`
- `permissions`
- `views`
- `bootstrap`
- `services`
- `assets`
- `routes`
- `listeners`
- `quick_actions`
- `entity_prefixes`

If a module cannot satisfy the validator, it is not a stable extension point yet.

## Module Boot Lifecycle

Modules are discovered and booted through `ModuleLoader`.

Lifecycle events published today include:

- `module.registered`
- `module.booted`

These are the safest event names to treat as stable for module lifecycle observation because they are emitted centrally by the loader.

If a module subscribes through manifest listeners, use a static callable string such as `Vendor\\Module\\Module::onBooted`. The target must already be loaded by the module entry, service files, or bootstrap before the loader registers listeners.

Listener declarations fail closed. If the manifest listener shape is invalid or the callable still cannot be resolved after bootstrap, the module fails compliance for that boot instead of silently dropping the listener.

Stable listener rules:

- event names must stay within lowercase dotted keys plus `*` wildcard segments
- wildcard patterns such as `module.*` are supported
- handlers must be static callable strings in the manifest contract
- priority must be numeric

## Routes

Extension routes should be declared through module manifests and executed through the shared router.

Guidelines:

- use explicit route names
- keep patterns constrained
- send requests through shared middleware expectations
- ensure route policy coverage exists for public routes
- ensure manifest-owned public routes are represented in the endpoint route inventory contract tests

Do not bypass the main router with ad hoc public PHP files.

## AJAX Controllers

For action-style browser/API requests, register controllers through:

- `metis_ajax_register_controller()`
- `metis_ajax_register_handler()`

Stable controller contract fields:

- `module`
- `permission`
- `methods`
- `nonce_action`
- `rate_limit`
- `rate_window_seconds`
- `allow_additional_fields`
- `schema`

If third-party modules depend on the AJAX surface, this registration contract is the extension boundary, not ad hoc `$_POST` parsing.

## Services

Shared services are exposed through the application service registry:

- `Application::service()`
- helper wrappers in `system/src/Metis/Core/ServiceRegistryRuntime.php`

Use this pattern when:

- exposing reusable module services
- consuming shared core capabilities
- avoiding direct singleton globals inside feature code

## Events

The event bus is exposed through:

- `metis_event_bus()`
- `metis_subscribe_event()`
- `metis_publish_event()`

Current event bus behavior supports:

- exact event names
- wildcard subscription patterns
- listener priority ordering
- propagation stop
- listener error isolation
- normalized lowercase event names
- emitted event timestamps
- recorded listener error metadata on the event object

Use events for loose coupling. Do not use them as a substitute for required synchronous contracts between tightly related classes.

## Built-In Service Patterns

Core-shipped product areas under `BuiltInServices` are useful references for stable extension structure:

- controller/request/policy separation
- service-based runtime logic
- manifest-like ownership even when shipped in core

When designing new module APIs, prefer these patterns over custom local conventions.

## What To Avoid

These are not good extension seams:

- direct file includes into unrelated modules
- reliance on undocumented globals
- bypassing router/security layers
- compatibility shims for retired entrypoints
- mutation of storage/runtime internals without a service boundary

## Stabilization Guidance

Before treating an extension seam as supported, confirm:

1. the entry contract is validated
2. authorization is explicit
3. request shape is documented
4. failure behavior is deterministic
5. a contract test exists where practical

That is the threshold Metis should use before third-party modules depend on a seam long term.
