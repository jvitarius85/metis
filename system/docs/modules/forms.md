# Forms

Build forms, share them, and review submissions.

## Routes

- Base route: `/forms`
- `/forms/dashboard` -> `dashboard.php`
- `/forms/form` -> `form.php`
- `/forms/build` -> `build.php`
- `/forms/entries` -> `entries.php`
- `/forms/settings` -> `settings.php`

## UI Components

- **Dashboard** template: `dashboard.php`
- **Form** template: `form.php`
- **Build** template: `build.php`
- **Entries** template: `entries.php`
- **Settings** template: `settings.php`

## APIs

- No dedicated AJAX controller was discovered for this module.

## Delivery-area restrictions

Each form can optionally restrict submissions to an allowlist of five-digit ZIP codes. Configure it in the builder's **Basics** settings, select a Personal information block with Address enabled, and mark that Address required. On public forms, the ZIP is checked as the visitor enters it and the submit or payment-continuation button remains unavailable until an allowed five-digit ZIP is present. The form explicitly explains when it is waiting for a ZIP, confirms eligibility when the button unlocks, and explains when delivery is unavailable. The server checks the submitted ZIP again before it creates a submission, bindings, notifications, or a payment intent. This is first-party validation only: Forms does not use browser location, IP geolocation, maps, or third-party address services.

## Database Tables Used

- `calendar_events` (`metis_calendar_events`)
- `campaigns` (`metis_campaigns`)
- `contacts` (`metis_contacts`)
- `form_submissions` (`metis_form_submissions`)
- `form_versions` (`metis_form_versions`)
- `forms` (`metis_forms`)
- `grandys_stash_catalog` (`metis_grandys_stash_catalog`)

## Assets and Extension Hooks

- CSS: `forms.css`
- JS: `forms.js`
