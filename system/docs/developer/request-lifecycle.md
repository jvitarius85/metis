# Request Lifecycle

This document describes how a request moves through the current Metis runtime.

## Entry Point

All web traffic enters through `index.php`. The entrypoint is intentionally thin and hands execution to the core runtime under `system/src/Metis`.

The main request flow is:

`index.php -> kernel bootstrap -> router -> security middleware -> handler -> response`

## Bootstrap

Core bootstrap lives in:

- `system/src/Metis/Core/CoreBootstrap.php`
- `system/src/Metis/Core/Kernel`
- `system/src/Metis/Core/Runtime`

Bootstrap is responsible for:

- defining runtime constants
- registering services
- loading modules and built-in services
- preparing router, AJAX, and security runtime state

## Router

HTTP routing is built in:

- `system/src/Metis/Core/Routing/RouterRuntime.php`

The router registers:

- asset routes
- auth API routes
- webhook routes
- cron routes
- AJAX routes
- batch API routes
- portal routes
- manifest-provided module routes
- core-owned public website routes such as `sitemap.xml`, `robots.txt`, theme CSS, homepage rendering, and people/page lookups

Every new public endpoint should be routed here or through manifest routes that still pass through the same runtime.

## Security Middleware

The router composes middleware groups before handlers execute.

Important middleware:

- `request.normalize`
- `request.security`
- `auth.security`
- `route.security`
- `ajax.contract`
- `ajax.security`
- `system.cron.security`

Route policy resolution happens in `metis_router_route_policy()` and is enforced through the secure enclave runtime.

## Route Types

### Portal routes

Portal requests run through the portal stack:

`portal.auth -> route.security -> request.security -> portal.permissions`

These are authenticated workspace routes and usually end in module views or built-in service views.

### AJAX routes

AJAX requests use `/api/ajax` and run through:

`request.security -> ajax.contract -> ajax.security`

The controller registry defines:

- module ownership
- permission
- nonce action
- methods
- rate limits
- input schema

Handlers execute only after contract and security checks pass.

### Auth API routes

Auth endpoints such as `/api/auth/resolve` and passkey endpoints run through:

`auth.security -> route.security`

These routes are public or session-bound depending on the specific endpoint.

### Webhooks

Webhook requests are routed through `webhook.gateway` at `/api/webhooks/{provider}` by default and then delegated into the webhook runtime under `system/src/Metis/Core/Webhooks`.

The base path is configurable through `webhook_base_path`, but it remains a router-owned namespace under `index.php` rather than a live standalone web directory.

Provider-specific signature validation happens in the webhook runtime, not in the front controller.

Webhook runtime enforcement also owns:

- provider allowlists and required headers
- replay-window timestamp validation where configured
- per-provider rate limiting
- provider quarantine after repeated failures
- `Retry-After` headers for throttled or quarantined requests

### Cron

Cron requests use `/api/cron` and run through:

`system.cron.security -> route.security`

Authorization happens first through the cron runtime, then route policy enforcement applies rate limiting and audit context.

## Module Loading

Modules are discovered and booted by:

- `system/src/Metis/Core/ModuleLoader.php`
- `system/src/Metis/Core/Modules/ModuleValidator.php`

During boot, Metis:

1. discovers manifests
2. validates structure and contracts
3. registers module policies
4. loads declared services/bootstrap files
5. registers listeners and routes
6. publishes module lifecycle events

Manifest listeners are not best-effort. If a declared listener cannot be resolved after module bootstrap, the module boot fails rather than silently discarding the listener.

## Response Generation

Responses are usually returned as:

- HTML for portal and public pages
- JSON for AJAX and API routes
- plain text for some asset/security failures

Cacheable asset routes emit `ETag` and `Last-Modified` headers, short-circuit `HEAD` requests without loading bodies, and may reuse the shared application cache for bundled asset bodies.

Runtime asset endpoints such as `bootstrap.js` and `theme.css` also reuse a per-context in-process asset bucket cache so the same `domain/view` request context does not rebuild inline assets twice during one request lifecycle.

Route security failures are normalized in `metis_router_route_security_failure_response()`.

## Audit And Observability

Security and route failures are recorded through:

- secure enclave logging
- audit log helpers
- `Metis_Logger`

When adding a new endpoint, confirm the request path produces:

- explicit route registration
- explicit route policy
- explicit authorization behavior
- consistent error response format
