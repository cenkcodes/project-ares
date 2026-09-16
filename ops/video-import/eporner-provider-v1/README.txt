Xurvexa / Project-Ares — Eporner Provider V1

PURPOSE
- Add Eporner as the first additional video source/provider.
- Use the official Eporner API v2 search + id endpoints.
- Preserve the existing XVideos collector, builder, orchestrator, V8 policy,
  Filament VideoImporter, source-term importer, taxonomy, and existing videos.
- Deployment performs PREPARE-ONLY smoke testing. It does not import Eporner
  videos into production yet.

NEW RUNTIME FILES
C:\Projects\Project-Ares\ops\video-import\eporner-api-collector.py
C:\Projects\Project-Ares\ops\video-import\eporner-csv-builder.py
C:\Projects\Project-Ares\ops\video-import\eporner-batch.php
C:\Projects\Project-Ares\ops\video-import\eporner-provider-bootstrap.php

PRODUCTION DESTINATIONS
/var/www/project-ares/ops/video-import/eporner-api-collector.py
/var/www/project-ares/ops/video-import/eporner-csv-builder.py
/var/www/project-ares/ops/video-import/eporner-batch.php
/var/www/project-ares/ops/video-import/eporner-provider-bootstrap.php

PACKAGE / DEPLOY SCRIPT
C:\Projects\Project-Ares\ops\video-import\eporner-provider-v1\deploy-eporner-provider-v1.ps1

HOW TO RUN
1. Extract the ZIP so the package folder is:
   C:\Projects\Project-Ares\ops\video-import\eporner-provider-v1
2. Run the complete PowerShell deploy script from that folder.
3. Send the complete terminal output back to ChatGPT.

PROVIDER SAFETY STATE CREATED BY V1
- provider slug: eporner
- active: yes
- monetization_enabled: no
- has_own_ads: yes (conservative provider-player assumption)
- Xurvexa preroll/midroll/popunder/native/banner/interstitial: all disabled

V1 DOES NOT
- modify xvideos-batch.php
- modify xvideos-url-collector.py
- modify xvideos-csv-builder.py
- modify video_import_policy.py
- modify import-video-source-terms.php
- modify VideoImporter.php
- import any Eporner videos during deployment

NEXT STEP AFTER ALL_COMPLETE=PASS
Run a deliberately small persistent Eporner import, validate DB/source-term
integrity, then visually verify the embedded player before scaling inventory.
