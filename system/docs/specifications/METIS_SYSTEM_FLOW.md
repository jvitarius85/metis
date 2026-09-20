# METIS SYSTEM FLOW
Version: 1.0

Defines the runtime flow used across Metis.

## Core Flow

`Request → Entrypoint → Kernel → Secure Enclave → Router / Dispatcher → Controller / Service → Response`

## HTTP Flow

`browser → index.php → Kernel → Router → Module Controller → Response`

## AJAX Flow

`frontend → /api/ajax → index.php → Kernel → Router → AjaxKernel → Secure Enclave → Action Dispatcher → Service / Controller → JSON`

## Webhook Flow

`external provider → /api/webhooks/{provider} → index.php → Kernel → Router → WebhookKernel → signature validation → action dispatcher → handler → response`

The webhook base path is router-owned. `webhook_base_path` may relocate the namespace, but Metis still resolves it through `index.php` rather than through standalone PHP files under web root.

## Cron Flow

`system cron → /api/cron → index.php → Kernel → Router → CronKernel → scheduled action dispatch → logs`

Cron authorization supports either the local shared secret or update-server signed headers. Signed requests bind the canonical path and request body digest, enforce a five-minute timestamp window, and reject replayed nonces.

## Shell Flow

`cli → system/shell.php → ShellKernel → command registry → action dispatch → output`

## Module Load Flow

`Kernel → ModuleLoader → module.json validation → dependency validation → Module.php boot/register → routes/actions/assets available`
