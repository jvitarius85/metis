# Directory Structure

This document reflects the current Metis layout in this repository, not older pre-runtime-consolidation layouts.

## Main Runtime Root

Core runtime code lives under:

- `system/src/Metis`

Important areas:

- `system/src/Metis/Core`
- `system/src/Metis/Release`
- `system/src/Metis/Services`
- `system/src/Metis/Auth`
- `system/src/Metis/Hermes`
- `system/src/Metis/Operations`

## Core Subsystems

Key directories under `system/src/Metis/Core`:

- `Ajax`: AJAX controller registry, validation, dispatch
- `Api`: non-AJAX API surfaces such as batch
- `Auth`: auth runtime and passkey/session endpoints
- `BuiltInServices`: built-in modules shipped with core
- `Cache`: cache adapters and cache services
- `Config`: config loading and normalization
- `Cron`: cron runtime and authorization
- `Editor`: shared editor/runtime block logic
- `Error`: error kernel and failure handling
- `Events`: event bus and event objects
- `Jobs`: queue and worker support
- `Kernel`: application bootstrap/kernel behavior
- `Modules`: module validation and module contracts
- `Recovery`: preboot and release recovery logic
- `Routing`: front-controller router and middleware
- `Runtime`: standalone/runtime boot helpers
- `Security`: secure enclave, policies, and guards
- `Services`: reusable core services
- `Webhooks`: inbound webhook runtime

## Built-In Services

Built-in product areas that ship from core live in:

- `system/src/Metis/Core/BuiltInServices/help`
- `system/src/Metis/Core/BuiltInServices/hermes`
- `system/src/Metis/Core/BuiltInServices/people`
- `system/src/Metis/Core/BuiltInServices/portal`
- `system/src/Metis/Core/BuiltInServices/profile`
- `system/src/Metis/Core/BuiltInServices/settings`

Treat these as first-class runtime modules even though they are stored inside core.

## Module Locations

Metis uses a split-module setup.

In this repository:

- core-owned module/runtime code lives in `system/src/Metis/Core/BuiltInServices`

In the sibling private bundle:

- deployable/private modules commonly live in `../metis-private/modules`

Examples include `website`, `board`, `finance`, `newsletter`, `drive`, `forms`, and `media`.

When changing module behavior, verify whether the source of truth is core or `metis-private`.

## Tests

Contract and runtime tests live in:

- `system/tests`

Useful categories:

- router and security contract tests
- runtime audit tests
- module install/update tests
- governance and production contract tests

## Tools And Operations

Operational scripts and release tooling live in:

- `system/tools`
- `tools`

Notable areas:

- `system/tools/update_server`
- `system/tools/run_system_cron.php`
- `tools/governance`

## Documentation

Project documentation lives in:

- `system/docs`
- `docs`

Use `system/docs` for product and developer/runtime documentation tied to the current platform.

## Storage And Generated Runtime Data

Runtime-generated state should stay in storage/runtime or other designated storage paths, not beside source files.

Common storage areas include:

- `storage/runtime`
- `storage/private-records`
- cache directories managed by runtime services

## Practical Rules

- Put new request handling into the router/runtime layers already in place.
- Put reusable behavior into services before adding more view/controller logic.
- Put module-specific code in the owning module, not in unrelated core directories.
- Do not create new public entrypoint files when the request can be routed through `index.php`.
