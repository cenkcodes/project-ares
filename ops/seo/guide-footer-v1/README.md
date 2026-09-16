Xurvexa Guide Footer V1 - Corrected Package

Correction
----------
The orphan integrity check now uses the actual video_source_terms schema:

video_source_terms.video_id -> videos.id

The prior package incorrectly assumed a video_slug column.

Production change remains unchanged
-----------------------------------
Only this existing file is modified after all prechecks pass:
/var/www/project-ares/backend/resources/views/partials/site-footer.blade.php

Windows project file synchronized after successful deployment
-------------------------------------------------------------
C:\Projects\Project-Ares\backend\resources\views\partials\site-footer.blade.php

Run
---
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Projects\Project-Ares\ops\seo\guide-footer-v1\deploy-guide-footer-v1.ps1"
