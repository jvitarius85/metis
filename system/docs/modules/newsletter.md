# Newsletter

Create email campaigns, manage newsletter theme settings, and track delivery.

## List Visibility

Each newsletter list has independent **status** and **visibility**. Active public lists are available to website newsletter signup forms. Active private lists remain available for internal membership management and campaign audiences, but cannot be selected or subscribed to through public website signup routes.

## Routes

- Base route: `/newsletter`
- `/newsletter/dashboard` -> `dashboard.php`
- `/newsletter/campaigns` -> `campaigns.php`
- `/newsletter/campaigns/{campaign-code}/details` -> sent campaign delivery and engagement statistics
- `/newsletter/theme` -> `theme.php`
- `/newsletter/editor` -> `editor.php`
- `/newsletter/lists` -> `lists.php`
- `/newsletter/subscribers` -> `subscribers.php`

## UI Components

- **Dashboard** template: `dashboard.php`
- **Campaigns** template: `campaigns.php`
- **Campaign details**: combines aggregate delivery counts, a native engagement funnel, recipient activity, delivery-health timing, and unique-recipient conversion rates. Delivery success uses resolved delivery results; open, click-through, and unsubscribe rates use delivered recipients; click-to-open uses unique opens.
- **Campaign actions**: rendered through `metis_action_pill()` / `Metis.ui.actionPill`. Navigation actions use `href`; queue, delete, and archive actions declare their centralized AJAX endpoint and scalar payload. Newsletter preserves its confirmation prompts, then invokes `Metis.ui.actionPill.request()` so nonce handling, busy state, error feedback, and success events remain shared. Large-text and navigation-label modes render the controls as a vertical, non-wrapping action group and use horizontal table scrolling rather than breaking labels into letters.
- **Theme** template: `theme.php`
- **Editor** template: `editor.php`
- **Lists** template: `lists.php`
- **Subscribers** template: `subscribers.php`

## APIs

- No dedicated AJAX controller was discovered for this module.

## Database Tables Used

- `contact_details` (`metis_contact_details`)
- `contacts` (`metis_contacts`)
- `newsletter_audit` (`metis_newsletter_audit`)
- `newsletter_campaign_lists` (`metis_newsletter_campaign_lists`)
- `newsletter_campaigns` (`metis_newsletter_campaigns`)
- `newsletter_events` (`metis_newsletter_events`)
- `newsletter_google_usage_daily` (`metis_newsletter_google_usage_daily`)
- `newsletter_lists` (`metis_newsletter_lists`)
- `newsletter_messages` (`metis_newsletter_messages`)
- `newsletter_revisions` (`metis_newsletter_revisions`)
- `newsletter_subs` (`metis_newsletter_subs`)
- `newsletter_suppressions` (`metis_newsletter_suppressions`)
- `newsletter_templates` (`metis_newsletter_templates`)
- `people_workspace_users` (`metis_people_workspace_users`)

## Assets and Extension Hooks

- CSS: `newsletter.css`
- JS: `newsletter.js`
- Registered help topics: `newsletter.dashboard`, `newsletter.campaigns`, `newsletter.theme`, `newsletter.lists`, `newsletter.subscribers`
