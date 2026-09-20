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

That same installation identity can also anchor signed cron triggers initiated by the update server. The update server signs the canonical cron route using its server private key, and the installation verifies the request against its stored `installation_id` plus the configured update-server public key.

The installer registers the installation during the final `complete` action, after core defaults and optional module installation, but before the install lock is written and the browser is redirected to `/admin/`.

That ordering is part of the hardening contract:

1. complete defaults
2. register the installation and verify scheduler/update-server cron probing
3. write the install lock
4. normalize runtime permissions

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

## Staging Flow

The hardened release flow is intentionally stage-first for both core and modules:

1. develop locally
2. sync sanitized release candidates to the update server staging area
3. run publish verification against the staged candidate
4. publish the signed archive from that staged candidate

That means the update server is not supposed to publish directly from an arbitrary working tree, release metadata row, or installation inventory record.

### Core staging

Core release candidates are staged under:

- `builds/core/metis-<version>/`

The staged payload excludes local-only and non-distributable paths such as:

- `storage/`
- `system/config/database.php`
- `system/config/update.php`
- `system/tests/`
- `system/tools/update_server/`
- `tools/`
- `docs/`
- non-index contents of `system/modules/`

The verification suite is staged separately under:

- `storage/verification-suite/current/`

It contains the contract-test dependencies needed to inspect staged builds:

- `system/assets/`
- `system/src/`
- `system/tests/`
- `system/tools/update_server/`
- `tools/governance/`

### Module staging

Standalone module release candidates are staged under:

- current payloads: `builds/modules/current/<module-slug>/`
- version baselines: `builds/modules/versions/<module-slug>/<version>/`

Module staging excludes non-runtime content such as:

- `tests/`
- `tools/`
- `docs/`
- `meta/`
- `storage/`

Versioned module staging is the baseline source for `module_delta` publication. Current module staging is the source for `module_full` publication and the target version of `module_delta`.

## Core Update Path

`ReleaseManager` archive mode now detects managed packages after extraction. If the archive is a signed update-server package:

- verify the manifest signature against the configured update-server public key
- validate package type and target version
- require `from_version` to match the currently installed version for deltas
- apply only the changed files and delete list
- retain the existing backup, integrity, recovery, and runtime-refresh flow

Legacy GitHub archive extraction remains intact for non-managed archives.

### Deployment worker

The Settings “Apply Release” action applies the trusted release immediately in
its authorized request and reports progress to the page. Queue-backed
`release.apply` and `release.rollback` operations remain reserved for the local
CLI worker, which must run as the installation's code owner (for example, from
that account's crontab invoking `system/tools/run_system_cron.php`).

## Recovery Path

Preboot release recovery prefers a git rollback when the prior ref is still available. If that fails, it falls back to the local rollback archive only after verifying the archive file against the recorded `sha256`. Rollback state is finalized only after a successful restore.

## Module Update Path

`ModuleInstallService` now accepts signed managed packages in addition to the legacy module archive format.

- `module_full` stages a full runtime bundle from `payload/`
- `module_delta` copies the existing runtime bundle into staging, applies the payload delta, and then re-validates the staged bundle

The existing staged-swap runtime install contract remains unchanged after staging.

## Archive Contract

The release archive contract now matches the staged publish contract:

- core archives are created from the same sanitized payload shape that is synced to `builds/core/`
- module archives are created from the same sanitized payload shape that is synced to `builds/modules/current/` and `builds/modules/versions/`

This prevents drift between:

- what `metis-release` stages
- what the update server verifies
- what the update server publishes
- what archive consumers ultimately install
