# Operations

- Configure backups, scheduler tasks, integrity baselines, and release checks from Settings.
- Backup failures are emailed immediately from Settings > Backup. Alerts are enabled by default; configure one or more recipients there, or leave the list blank to use the primary system administrator email. Each failed run records its alert delivery status so stale-run reconciliation cannot silently repeat notifications.
- Regenerate repository documentation after significant schema or manifest changes by running `php tools/generate_docs.php`.
- Review `docs/database/schema.md` after schema changes to confirm indexes and lifecycle notes remain accurate.
- Review `docs/operations/metis-audit-status-2026-07.md` when tracking progress against the current hardening and audit scope.
