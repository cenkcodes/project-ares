Xurvexa Eporner Normalization V1.2
==================================

Purpose
-------
Fix Eporner source-term normalization mismatches between Python's
unicodedata normalization and Laravel VideoTaxonomyResolver::normalizeTerm()
(Str::ascii).

Scope
-----
- Replaces ONLY eporner-csv-builder.py.
- Does NOT modify video_import_policy.py.
- Does NOT modify VideoTaxonomyResolver.php.
- Does NOT modify import-video-source-terms.php.
- Does NOT modify XVideos files.
- Does NOT import videos during deployment.

Behavior
--------
Eporner metadata/policy handling remains unchanged. Only source-term sidecar
rows are restricted to plain ASCII terms, where Python and Laravel
normalization are guaranteed to agree.

Deployment smoke
----------------
Runs a prepare-only Mature batch, then recomputes every generated
normalized_term through the live Laravel VideoTaxonomyResolver. Deployment
fails if any mismatch or non-ASCII source-term remains.

Windows project path
--------------------
C:\Projects\Project-Ares\ops\video-import\eporner-normalization-v1-2

Run
---
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Projects\Project-Ares\ops\video-import\eporner-normalization-v1-2\deploy-eporner-normalization-v1-2.ps1"
