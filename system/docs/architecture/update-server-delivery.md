# Update Server Delivery

## Scope

Metis now supports a first-party update server flow alongside the legacy GitHub release path. The update server is intended to become the trusted source for:

- installation registration
- authenticated update checks
- installation-scoped custom module distribution
- signed package delivery
- file-level delta updates for core and modules

## Installation Identity

Each Metis install generates its own RSA keypair and stores it in:

- `storage/private-records/update-server/identity.json`

The identity payload includes:

- `machine_uuid`
- `server_fingerprint`
- `base_url`
- `channel`
- public/private keypair

The installer registers the installation during the final `complete` action, after core defaults and optional module installation, but before the install lock is written and the browser is redirected to `/admin/`.

## Provider Seam

The existing `github_update` service name remains the integration seam used by:

- release manager
- module installer
- settings update UI
- cron update polling
- Hermes update/status flows

When `system/config/update.php` sets `source = update_server`, `GitHubUpdateService` delegates metadata polling and archive downloads to `UpdateServerClient`.

## Signed Package Format

Update-server packages are signed archives with:

- `metis-package.json`
- `metis-package.sig`
- `payload/...`

Supported package types:

- `core_full`
- `core_delta`
- `module_full`
- `module_delta`

The manifest lists changed files and deleted paths. Core package application still respects protected paths such as `storage/**`, `system/config/**`, and other local-only runtime state.

## Core Update Path

`ReleaseManager` archive mode now detects managed packages after extraction. If the archive is a signed update-server package:

- verify the manifest signature against the configured update-server public key
- validate package type and target version
- require `from_version` to match the currently installed version for deltas
- apply only the changed files and delete list
- retain the existing backup, integrity, recovery, and runtime-refresh flow

Legacy GitHub archive extraction remains intact for non-managed archives.

## Module Update Path

`ModuleInstallService` now accepts signed managed packages in addition to the legacy module archive format.

- `module_full` stages a full runtime bundle from `payload/`
- `module_delta` copies the existing runtime bundle into staging, applies the payload delta, and then re-validates the staged bundle

The existing staged-swap runtime install contract remains unchanged after staging.
