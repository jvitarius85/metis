# Module Store Migration

Metis is migrating toward a module-store model where installable feature modules are delivered as versioned `tar.gz` bundles and loaded from the runtime module root.

## Runtime Contract

- `system/modules/` is the runtime source of truth for store-managed modules.
- `system/src/Metis/Core/BuiltInServices/` is the source of truth for built-in core services.
- `system/src/Metis/Modules/` still contains legacy source-side implementations that must be migrated deliberately before they can be removed.

## Current Ownership Model

- Store-managed runtime modules:
  - `board`
  - `calendar`
  - `contacts`
  - `donations`
  - `drive`
  - `finance`
  - `forms`
  - `grandys_stash`
  - `import`
  - `media`
  - `newsletter`
  - `resources`
  - `testimonies`
  - `website`
- Built-in core services:
  - `help`
  - `hermes`
  - `modules`
  - `people`
  - `portal`
  - `profile`
  - `settings`
- Transitional source-side modules:
  - `communicationsinbound`
    - Target: `communications_inbound_runtime`
    - Direction: keep as a core runtime integration surface that installed modules can call into.
  - `formsimport`
    - Target: `forms`
    - Direction: fold import behavior into the Forms runtime module.
    - Status: standalone installer/runtime schema registration removed; compatibility shim remains only to delegate to Forms.
  - `grandystash`
    - Target: `grandys_stash`
    - Direction: normalize the legacy source directory name to the runtime/store slug.

## Current Source Inventory

`system/src/Metis/Modules/` is no longer a runtime discovery root, but it still contains legacy implementation code that must be migrated deliberately.

- Legacy source-backed store modules still present:
  - `board`
  - `calendar`
  - `contacts`
  - `donations`
  - `drive`
  - `finance`
  - `forms`
  - `import`
  - `media`
  - `newsletter`
  - `resources`
  - `testimonies`
  - `website`
- Transitional source-only exceptions:
  - `communicationsinbound`
  - `formsimport`
  - `grandystash`
- Built-in core service source directories that remain valid:
  - `help`
  - `hermes`
  - `modules`
  - `people`
  - `portal`
  - `profile`
  - `settings`

The canonical inventory lives in `ModulePathRegistry::sourceModuleInventory()`. Any new directory added under `system/src/Metis/Modules/` should fail contract tests until the migration inventory and runtime plan are updated intentionally.

## Bundle Contract

Each published module bundle should unpack to:

```text
module-slug/
  module.json
  Module.php
  bootstrap.php
  views/
  assets/
  templates/
  services/
  ajax/
  entities/
```

The bundle must be self-contained for runtime behavior. It should not require feature implementation classes to already exist under `system/src/Metis/Modules/`.

Metis now primes the declared module `entry` file before module-class resolution. This means a bundle can carry a real `Module.php` implementation instead of relying on a placeholder plus pre-bundled source classes.

Use the bundle audit tool before publishing or packaging a module:

```bash
php system/tools/module_bundle_audit.php /path/to/module-slug
```

To audit an entire bundle source root and see which modules still depend on source-side placeholders:

```bash
php system/tools/module_bundle_audit.php /path/to/modules-root
```

The audit returns JSON and exits non-zero when:

- `module.json` is invalid or fails the runtime validator
- the declared entry file is missing
- the entry file is still a placeholder instead of defining the expected module class
- `bootstrap.php` declares a helper that collides with an already-loaded runtime function

The audit also reports `runtime_coupling_status` for each bundle:

- `bundle_only`
  - Runtime PHP files do not reference source-side module namespaces or `METIS_SRC_PATH`.
- `source_coupled`
  - Runtime PHP still reaches into source-side module namespaces, core service namespaces, or source-path includes.

This means a bundle can be entry-contract compliant while still needing a later runtime-isolation pass.

## Current Audit Snapshot

Current bundle-audit baseline:

- Entry contract readiness:
  - `14/14` runtime bundles define their own module entry class.
- Runtime isolation readiness:
  - `14/14` currently audit as `bundle_only`
  - `0/14` currently audit as `source_coupled`

## Migration Rules

- Do not add new store-managed feature logic under `system/src/Metis/Modules/`.
- New store-managed module work should ship inside the runtime bundle under `system/modules/<slug>/`.
- Treat `system/modules/` as the only runtime location for store-delivered modules. Do not split feature behavior between runtime bundles and source-side module stubs.
- Runtime discovery, compliance, recovery, and asset resolution should read the installed runtime module root, not source-side stubs.
- Remove source-side module implementations only after the matching runtime bundle is self-contained and verified in deployment.
