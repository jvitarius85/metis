# Metis Namespace Layout

- `Metis\Core`: runtime bootstrap, container, module loader, router orchestration.
- `Metis\Services`: adapters around auth, logging, settings, and database access.
- `Metis\Http`: request/response/router primitives.
- `Metis\Modules\<ModuleName>`: reserved for module-specific classes as modules move out of bootstrap function files.

# Compatibility Rules

- Legacy underscore class names remain available through class aliases.
- Global helper functions remain supported for now; new class-based code should live under `src/Metis`.
- The old `includes/core/*`, `includes/apis/stripe/*`, and `includes/modules/*` runtime compatibility shims have been removed; live implementation ownership is now under `src/Metis` and `modules`.
- The old `core/services/*`, `core/ui/*`, and live `core/integrations/stripe/*` runtime ownership has been migrated into `src/Metis` and `assets`; any remaining `core/*` file should be treated as cleanup debt, not an approved runtime location.
- Root-level duplicate aliases for `Metis\Core\Event`, `Metis\Core\EventBus`, `Metis\Core\JobQueue`, and `Metis\Core\JobWorkerRegistry` have been removed; the canonical owners are `Metis\Core\Events\*` and `Metis\Core\Jobs\*`.

# Suggested Module Structure

- `modules/donations/Module.php`
- `modules/donations/StripeDepositService.php`
- `modules/donations/StripeReconciliationService.php`

# Reference Migration

- `Donations` remains the reference migration for store-managed module packaging.
- The long-term runtime entrypoint is the module bundle under `system/modules/donations/` after installation.
- During development, the canonical bundle source lives in the sibling private workspace under `../metis-private/modules/donations/`.
- `modules/donations/Module.php` is the runtime entrypoint for the Donations bundle.
- The same rule applies to the other store-managed bundles under `system/modules/`.

# Current Module Pattern

- Store-managed modules must be self-contained bundles with `module.json`, `Module.php`, and `bootstrap.php` in the runtime module root.
- Built-in services remain under `src/Metis/Core/BuiltInServices/`.
- `modules/<slug>` is the valid target for store-managed feature work and runtime entry classes.
- The retired source-module mirror must not be reintroduced.

# Scaffold

- A reusable new-module scaffold now lives under `scaffolds/module/`.
- It provides starter templates for `bootstrap.php`, the module class, and `module.json`.
- The scaffold is template text, not executable PHP, because it contains replacement tokens.

# Migration Approach

1. Add namespaced classes under `src/Metis`.
2. Preserve existing call sites with class aliases.
3. Move new behavior into classes first, then retire legacy bootstrap functions incrementally.
