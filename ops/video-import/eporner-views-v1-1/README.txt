Xurvexa Eporner Views V1.1

Purpose
- Keep Xurvexa videos.views as a site-local counter.
- Stop copying Eporner provider view totals into new Xurvexa video rows.
- Reset only existing Eporner video rows to views=0.
- Preserve XVideos rows and all other providers unchanged.

Production change
- Complete replacement: /var/www/project-ares/ops/video-import/eporner-csv-builder.py
- One-time staged helper: reset-eporner-views.php
- No change to eporner-batch.php, XVideos files, shared V8 policy, VideoImporter, or source-term importer.

Windows destination
C:\Projects\Project-Ares\ops\video-import\eporner-views-v1-1

Run
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Projects\Project-Ares\ops\video-import\eporner-views-v1-1\deploy-eporner-views-v1-1.ps1"

Expected final marker
EPORNER_VIEWS_V1_1_ALL_COMPLETE=PASS
