# Xurvexa Guide Hub V2

Expands the proven Guide Hub V1 architecture from 4 to 12 active editorial guides.

## Scope

- Adds 8 guides through the complete `config/guide-seo.php` file.
- No migration.
- No controller, route, view, middleware, sitemap-template or category-SEO architecture change.
- Existing 4 V1 guide definitions are validated as unchanged after installation.
- Main sitemap grows from 15,487 to 15,495 URLs.
- Video sitemap remains 15,455 entries.

## New guide URLs

- `/guides/pov-videos-explained`
- `/guides/amateur-videos-explained`
- `/guides/blonde-vs-brunette`
- `/guides/japanese-video-categories`
- `/guides/mature-video-categories`
- `/guides/how-xurvexa-categories-work`
- `/guides/video-tags-and-categories`
- `/guides/find-videos-by-category`

## Windows deployment root

`C:\Projects\Project-Ares\ops\seo\guide-hub-v2`

Run:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Projects\Project-Ares\ops\seo\guide-hub-v2\deploy-guide-hub-v2.ps1"
```

## New guide QA word counts

```json
{
  "pov-videos-explained": 671,
  "amateur-videos-explained": 624,
  "blonde-vs-brunette": 666,
  "japanese-video-categories": 621,
  "mature-video-categories": 626,
  "how-xurvexa-categories-work": 675,
  "video-tags-and-categories": 653,
  "find-videos-by-category": 677
}
```
