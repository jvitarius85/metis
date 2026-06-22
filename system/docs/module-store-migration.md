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

## Migration Rules

- Do not add new store-managed feature logic under `system/src/Metis/Modules/`.
- New store-managed module work should ship inside the runtime bundle under `system/modules/<slug>/`.
- Runtime discovery, compliance, recovery, and asset resolution should read the installed runtime module root, not source-side stubs.
- Remove source-side module implementations only after the matching runtime bundle is self-contained and verified in deployment.
