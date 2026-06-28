# Module Store Migration

Metis is migrating toward a module-store model where installable feature modules are delivered as versioned `tar.gz` bundles and loaded from the runtime module root.

## Runtime Contract

- `system/modules/` is the runtime source of truth for store-managed modules.
- `system/src/Metis/Core/BuiltInServices/` is the source of truth for built-in core services.
- The retired source-module tree has already been removed. Store-managed behavior now resolves from runtime bundles, while built-in and transitional runtime code stays under core-owned paths.
- In development, the canonical bundle source currently lives in the sibling private workspace at `../metis-private/modules/` unless `METIS_PRIVATE_MODULES_ROOT` overrides it.
- Store-managed modules own their own table creation. Runtime entry classes should expose `ensureRuntimeSchema()` or `ensureSchema()`, and module-store installs should call that entrypoint after the bundle is promoted.

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

## Current Source Inventory

The old source-module tree is retired and is no longer part of runtime discovery or packaging.

- Legacy source-backed store modules still present:
  - `board`
  - `calendar`
  - `contacts`
  - `donations`
  - `drive`
  - `finance`
  - `forms`
  - `grandystash`
  - `import`
  - `media`
  - `newsletter`
  - `resources`
  - `testimonies`
  - `website`
- Transitional source-only exceptions:
  - `communicationsinbound`
  - `formsimport`
- Built-in core services now live under `system/src/Metis/Core/BuiltInServices/`.
- Transitional core runtime code now lives under `system/src/Metis/Core/TransitionModules/`.

The canonical inventory lives in `ModulePathRegistry::sourceModuleInventory()`. New store-managed feature work should ship as bundle content, not as a source-side mirror.

Metis also tracks the current set of core-to-store-module namespace dependencies in `system/tests/module_store_dependency_boundary_test.php`. That boundary is intentionally one-way:

- Existing references may be removed as runtime bundles become self-contained.
- New references from core, Hermes, or built-in services into legacy store-module namespaces should fail tests until the migration plan is updated deliberately.

## Current Runtime Bridge Surface

The remaining bridge frontier for retiring legacy store-managed source implementations is declared in `ModulePathRegistry::legacyStoreManagedRuntimeBridges()`:

- `src/Metis/Core/Runtime/ModuleSchemaRuntimeBridge.php`
  - Current scope: `board`, `calendar`, `contacts`, `finance`, `forms`, `import`, `newsletter`, `website`

This is the remaining schema/bootstrap migration frontier. Entry-resolver-only runtime bridges such as the website and newsletter bridges are no longer treated as source-retirement blockers by themselves.

The stricter subset of bridges that still directly reference legacy store-managed source namespaces is declared separately in `ModulePathRegistry::legacyStoreManagedDirectRuntimeBridges()`. That smaller set is what `system/tests/module_store_dependency_boundary_test.php` enforces.

Bridge implementation rule:

- Runtime bridges should prefer module entry classes such as `WebsiteModule`, `NewsletterModule`, `ContactsModule`, and similar facades when those entry points exist.
- Bridge logic should not drift back to internal source services or schema managers when an equivalent module entry method is available.

This rule is enforced by `system/tests/module_runtime_bridge_contract_test.php`.

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

The bundle must be self-contained for runtime behavior. It should not require feature implementation classes outside the bundle or approved core-owned runtime surfaces.

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
- `core_runtime_dependency`
  - Runtime PHP depends only on approved built-in core services or transitional core runtime surfaces such as `communications_inbound`.
- `source_coupled`
  - Runtime PHP still reaches into unsupported source-side namespaces or source-path includes.

This means a bundle can be entry-contract compliant while still needing a later runtime-isolation pass.

To audit whether the remaining source-side module tree can be deleted safely:

```bash
php system/tools/module_source_retirement_audit.php
```

The retirement audit currently treats these as hard blockers:

- any legacy source-backed store module missing a bundle replacement in the development bundle source root
- any remaining direct source-coupled runtime bridges
- any unresolved runtime class loading path that still depends on a removed source-side module mirror

## Current Audit Snapshot

Current bundle-audit baseline:

- Entry contract readiness:
  - `14/14` runtime bundles define their own module entry class.
- Runtime isolation readiness:
  - `11/14` currently audit as `bundle_only`
  - `3/14` currently audit as `core_runtime_dependency`
  - `0/14` currently audit as `source_coupled`

Current delete-readiness interpretation:

- Bundle coverage can be complete while delete readiness is still false.
- Runtime class loading no longer depends on the retired source-module mirror.
- The remaining tree is now the legacy store-backed source mirror set tracked by `module_source_retirement_audit.php`.

## Migration Rules

- Do not reintroduce a source-side module mirror for store-managed feature logic.
- New store-managed module work should ship inside the runtime bundle under `system/modules/<slug>/`.
- Treat `system/modules/` as the only runtime location for store-delivered modules. Do not split feature behavior between runtime bundles and source-side module stubs.
- Runtime discovery, compliance, recovery, and asset resolution should read the installed runtime module root, not source-side stubs.
- Remove source-side module implementations only after the matching runtime bundle is self-contained and verified in deployment.
