# Settings

Configure the workspace, connected services, system operations, and recovery tools.

## Navigation

Settings are grouped around administrator tasks while retaining the existing routes:

- **Workspace**: General, User Experience, Branding, and Navigation.
- **Services & Integrations**: Email, Payments, Google Workspace, Calendar, and Drive.
- **Development & Operations**: API & Endpoints, Logging, Cache, Jobs & Tasks, and System Health. The legacy Runtime route remains available for existing links but is not shown in the administrator navigation.
- **Recovery & Support**: Backup, About, and Help. Modules Store remains a separate administration area.

The sidebar keeps the active group expanded and lets administrators expand other groups as needed. Links are alphabetized within each group, reducing visual noise without hiding any setting or changing its URL, permission, or save contract.

## Routes

- Base route: `/settings`
- `/settings/identity` -> `identity/index.php`
- `/settings/organization` -> `organization/index.php`
- `/settings/developers` -> `developers/index.php`
- `/settings/system` -> `system/index.php`
- `/settings/security` -> `security/index.php`
- `/settings/data` -> `data/index.php`
- `/settings/platform` -> `platform/index.php`
- `/settings/help` -> `help/index.php`

## UI Components

- **Identity** template: `identity/index.php`
- **Organization** template: `organization/index.php`
- **Developers** template: `developers/index.php`
- **System** template: `system/index.php`
- **Security** template: `security/index.php`
- **Data** template: `data/index.php`
- **Platform** template: `platform/index.php`
- **Help** template: `help/index.php`

## APIs

- No dedicated AJAX controller was discovered for this module.

## Database Tables Used

- `audit_security` (`metis_audit_security`)

## Assets and Extension Hooks

- CSS: `settings.css`
- JS: `settings.js`
- Registered help topics: `settings.help`
