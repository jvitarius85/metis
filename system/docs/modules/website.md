# Website

Structured website management for pages, posts, categories, menus, redirects, templates, and theme.

## Routes

- Base route: `/website`
- `/website/dashboard` -> `dashboard.php`
- `/website/analytics` -> `analytics.php`
- `/website/editor` -> `editor.php`
- `/website/pages` -> `pages.php`
- `/website/posts` -> `posts.php`
- `/website/categories` -> `categories.php`
- `/website/media` -> `media.php`
- `/website/banners` -> `banners.php`
- `/website/menus` -> `menus.php`
- `/website/popups` -> `popups.php`
- `/website/redirects` -> `redirects.php`
- `/website/templates` -> `templates.php`
- `/website/theme` -> `theme.php`
- `/website/import` -> `import.php`
- Public SEO routes: `/sitemap.xml`, `/robots.txt`, `/v1/website/theme.css`

## UI Components

- **Dashboard** template: `dashboard.php`
- **Editor** template: `editor.php`
- **Pages** template: `pages.php`
- **Posts** template: `posts.php`
- **Categories** template: `categories.php`
- **Media** template: `media.php`
- **Banners** template: `banners.php`
- **Menus** template: `menus.php`
- **Popups** template: `popups.php`
- **Redirects** template: `redirects.php`
- **Templates** template: `templates.php`
- **Theme** template: `theme.php`
- **Import** template: `import.php`

## APIs

- Admin AJAX controller: `ajax/website.ajax.php`
- Public route handlers: `routes/routes.php`
- Shared SEO service: `modules/website/Services/SeoService.php`

## SEO Guarantees

- Shared SEO metadata comes from `SeoService`, not per-page inline templates.
- Public website routes expose `/sitemap.xml` and `/robots.txt`.
- Placeholder summaries such as `testing`, `todo`, or `sample` are replaced with fallback descriptions before render.
- Public rendering is hardened to keep one primary `H1` per page and normalize empty emphasis tags from rich text blocks.

## Database Tables Used

- `website_banners` (`metis_website_banners`)
- `website_blocks` (`metis_website_blocks`)
- `website_global_layouts` (`metis_website_global_layouts`)
- `website_menus` (`metis_website_menus`)
- `website_pages` (`metis_website_pages`)
- `website_popups` (`metis_website_popups`)
- `website_post_categories` (`metis_website_post_categories`)
- `website_post_category_map` (`metis_website_post_category_map`)
- `website_post_tags` (`metis_website_post_tags`)
- `website_post_tag_map` (`metis_website_post_tag_map`)
- `website_posts` (`metis_website_posts`)
- `website_redirects` (`metis_website_redirects`)
- `website_analytics_daily` (`metis_website_analytics_daily`)
- `website_analytics_visits` (`metis_website_analytics_visits`)
- `website_analytics_dimensions` (`metis_website_analytics_dimensions`)
- `website_analytics_events` (`metis_website_analytics_events`)

## First-Party Analytics

Website → Analytics reports page views, cookie-based unique visitors, top pages, traffic sources, device/browser groups, UTM campaign activity, and aggregated engagement such as calls-to-action, downloads, external/contact links, popups, forms, scroll depth, and active time. It uses no external provider and never stores IP addresses, raw user agents, or query strings. A random first-party cookie is hashed before short-lived visitor deduplication; its hashes are pruned after 90 days while daily aggregate reporting remains available. Do Not Track and Global Privacy Control requests are respected.
- `website_revisions` (`metis_website_revisions`)
- `website_templates` (`metis_website_templates`)
- `website_theme_config` (`metis_website_theme_config`)
- `website_web_parts` (`metis_website_web_parts`)

## Assets and Extension Hooks

- CSS: `website.css`, `theme-admin.css`
- JS: `website.js`
- Image Carousel block: supports up to 12 images, smooth slide, fade, crossfade, tile-flip, reveal, or cinematic transitions, a per-image display duration, and either a link or an existing Website popup action. Slide pushes the outgoing image offscreen as the next image enters; tile-flip builds a dense responsive grid of roughly 48px tiles from the outgoing image, then alternates horizontal and vertical flips to reveal the next image underneath. Configure popup forms in Website → Popups, then select that popup for the image action.
