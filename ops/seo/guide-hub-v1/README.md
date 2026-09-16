# Xurvexa Guide Hub V1

Pilot editorial guide hub for Project-Ares / Xurvexa.

## Pilot URLs

- `/guides/milf-vs-mature`
- `/guides/asian-vs-japanese`
- `/guides/bbw-vs-big-ass-vs-big-tits`
- `/guides/cumshot-vs-creampie`

## Design

- Laravel-native, config-driven editorial content.
- No database migration.
- Existing verified `RequireAdultConsent` middleware remains on guide routes.
- Production guide pages are `index,follow`; non-production is `noindex,nofollow`.
- Main sitemap gains `/guides` and the four pilot guide URLs.
- Nine relevant category pages gain contextual links into the guide hub.
- No FAQ schema and no mass-generated keyword pages.

## Local deployment root

`C:\Projects\Project-Ares\ops\seo\guide-hub-v1`

Run:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Projects\Project-Ares\ops\seo\guide-hub-v1\deploy-guide-hub-v1.ps1"
```

The script performs hash-locked production prechecks, baseline checks, backup, rollback-on-failure, render validation, sitemap validation and local project sync.

## Content QA word counts

{
  "milf-vs-mature": 658,
  "asian-vs-japanese": 600,
  "bbw-vs-big-ass-vs-big-tits": 608,
  "cumshot-vs-creampie": 619
}
