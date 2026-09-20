# Metis Audit Status 2026-07

This document maps the current Metis hardening work against the five requested audit tracks.

## 1. Security Audit

Status: materially improved, still expanding evidence.

Current state:

- Public web traffic is consolidated through `index.php` and the shared router rather than live `api/cron/webhooks` web directories.
- Public routes, AJAX routes, auth routes, webhook routes, and `/api/cron` now run through explicit route registration and route-policy enforcement.
- Router, webhook, and cron tests cover authorization, public-route inventory, webhook base-path routing, replay/timestamp protections, and cron shared-secret plus signed update-server authorization.
- Auth request tests cover CSRF and route-specific nonce enforcement for public auth APIs.

Remaining work:

- Keep extending executable endpoint-by-endpoint coverage as additional public routes or providers are added.
- Continue reviewing older module surfaces for places where rate limits or request schemas should be tightened further.

## 2. Automated Testing

Status: strong progress.

Current state:

- Installer coverage includes defaults, completion, and runtime bootstrap expectations.
- Routing coverage includes route-policy inventory, asset cache behavior, auth request security, webhook routing, and cron authorization.
- Routing coverage now also includes runtime system-cron middleware behavior at the router boundary.
- Updater coverage includes release recovery/archive contracts and runtime package signature plus staged payload application tests.
- Updater coverage now also includes runtime preboot archive recovery and release boot-verification lifecycle tests.
- Core-service coverage includes event bus seams, manifest listener validation, runtime asset caching, auth shell asset caching, and website sitemap performance behavior.

Remaining work:

- Broaden full-path integration tests around more module-owned public endpoints as those surfaces stabilize.
- Add more failure-injection coverage where updater or installer flows depend on filesystem interruption scenarios.

## 3. Documentation

Status: in place.

Current state:

- Developer docs now cover request lifecycle, directory structure, coding standards, extension points, API endpoints, installer/setup flow, and update-server delivery.
- The documentation tree is indexed through `system/docs/README.md` and `system/docs/developer/developer-guide.md`.
- This status document now provides a single review point against the requested audit scope.

Remaining work:

- Keep the endpoint and extension docs synchronized as new hooks, listeners, or public routes are introduced.

## 4. Updater Hardening

Status: materially improved, not finished forever.

Current state:

- Update-server package flows require detached signature verification before payload application.
- Package application stages files through temporary destinations before promotion and supports guarded path policies.
- Release recovery keeps verified rollback archives, validates recorded hashes before archive replay, and preserves rollback finalization ordering.
- Preboot recovery has executable coverage for local archive fallback when git rollback is unavailable, including sha256 mismatch refusal and protected-path preservation.
- Post-update boot verification now has executable coverage for decrementing pending verification passes and clearing the transaction when healthy boots complete.
- Archive replay now also has executable failure-path coverage when staged restore promotion is blocked by a live target-path conflict.
- Installer/update documentation now calls out preboot recovery and rollback behavior explicitly.

Remaining work:

- Keep adding interruption-oriented tests around rollback and partial promotion paths.
- Continue validating that future update package formats preserve the same cryptographic and staged-write guarantees.

## 5. Extension API

Status: substantially stabilized.

Current state:

- Module manifest validation is fail-closed for invalid listeners and malformed extension metadata.
- Event bus tests cover normalized names, wildcard subscriptions, timestamps, listener ordering behavior, and failure metadata.
- Developer docs define the supported manifest, route, AJAX, service, and event seams and explicitly discourage compatibility shims and ad hoc public entrypoints.

Remaining work:

- Maintain contract tests as new manifest fields, events, or extension seams are introduced so third-party modules have a stable boundary.

## Current Architectural Direction

- Keep a single front-controller entrypoint through `index.php` and the shared router.
- Keep `/api/cron` and `/api/webhooks/*` as router-owned namespaces, not standalone public directories.
- Keep changes fail-closed and avoid compatibility wrappers or shims for retired entry surfaces.
