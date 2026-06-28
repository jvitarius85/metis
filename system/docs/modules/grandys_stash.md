# Grandy's Stash

Track equipment intake, inventory, and distributions.

## Routes

- Base route: `/grandys_stash`
- `/grandys_stash/dashboard` -> `dashboard.php`
- `/grandys_stash/reports` -> `report.php`
- `/grandys_stash/settings` -> `settings.php`

## UI Components

- **Dashboard** template: `dashboard.php`
- **Reports** template: `report.php`
- **Settings** template: `settings.php`

### Reports Highlights

- Date-range reporting includes KPI summary cards, monthly trend visualization, and grouped breakdown tables.
- The report builder supports server-backed filtering, sorting, and pagination for ticket history.
- Report exports can generate PDF output for the current filtered report scope.

## APIs

- No dedicated AJAX controller was discovered for this module.

## Database Tables Used

- No table references were discovered.

## Assets and Extension Hooks

- CSS: `grandys_stash.css`
- JS: `grandys_stash.js`
