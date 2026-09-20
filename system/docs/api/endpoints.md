# API and AJAX Endpoints

Metis routes most interactive behavior through the shared AJAX endpoint exposed by `src/Metis/Core/Ajax/AjaxRuntime.php` and routed by `src/Metis/Core/Routing/RouterRuntime.php`.

## Canonical Public Endpoints

- `GET|POST /api/ajax`
- `POST /api/cron`
- `POST /api/webhooks/{provider}`
- `GET|POST /admin/*`
- `GET|POST /portal/*`

## Routing Rules

- Web traffic enters through `index.php`.
- Route selection is handled by the kernel and router.
- Webhook traffic uses the router-owned `webhook.gateway` namespace. The default public path is `/api/webhooks/{provider}`, and `webhook_base_path` may move that namespace without reintroducing standalone PHP entry files.
- Webhook providers are expected to enforce signature or bearer-token verification inside the webhook runtime, plus replay-window checks, rate limiting, and quarantine-on-repeated-failure behavior.
- `/api/cron` accepts either the configured local cron secret or update-server signed headers; signed requests are timestamp-bounded and nonce-protected against replay.
- Legacy wrapper entrypoints under `/system/*` are unsupported.
- Short alias routes such as `/e/ac`, `/e/cj`, and `/e/wh/{provider}` are unsupported.
