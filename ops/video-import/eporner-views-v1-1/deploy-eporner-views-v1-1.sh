#!/usr/bin/env bash
set -euo pipefail

STAGE="${1:-}"
OPS="/var/www/project-ares/ops/video-import"
BACKEND="/var/www/project-ares/backend"
OLD_BUILDER_SHA="0f929cba3e3bf6c7a84bf5af28bef711186850f8287426a768ed4b0005ac4eba"
NEW_BUILDER_SHA="fb83e94ca78ac66f364054d4f7d332f2b4befcf16b1991bb68df70329e2d9e3f"
RESET_SHA="fbec18f0eba0aa95bc47613b46ecd67bb5f0737bbd8342bbf3e988c0aa21e983"
EXPECTED_BATCH_SHA="6af038866edf286657a5eae5049ba275b31c82984f183573b53acd12a7f8c72c"
EXPECTED_POLICY_SHA="9f71752390a9b6101f51cdfa8e2ddad56fe1e364294256e59bc80ef40aa3fb52"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$OPS/backups/eporner-views-v1-1-$STAMP"

if [[ -z "$STAGE" || ! -d "$STAGE" ]]; then
    echo "ERROR=Stage directory missing." >&2
    exit 1
fi

BUILDER_STAGE="$STAGE/eporner-csv-builder.py"
RESET_STAGE="$STAGE/reset-eporner-views.php"
BUILDER_PROD="$OPS/eporner-csv-builder.py"
BATCH_PROD="$OPS/eporner-batch.php"
POLICY_PROD="$OPS/video_import_policy.py"

for f in "$BUILDER_STAGE" "$RESET_STAGE" "$BUILDER_PROD" "$BATCH_PROD" "$POLICY_PROD"; do
    if [[ ! -f "$f" ]]; then
        echo "ERROR=Required file missing: $f" >&2
        exit 1
    fi
done

echo "===== EPORNER VIEWS V1.1 PACKAGE PRECHECK ====="
STAGE_BUILDER_SHA="$(sha256sum "$BUILDER_STAGE" | awk '{print $1}')"
STAGE_RESET_SHA="$(sha256sum "$RESET_STAGE" | awk '{print $1}')"
CURRENT_BUILDER_SHA="$(sha256sum "$BUILDER_PROD" | awk '{print $1}')"
CURRENT_BATCH_SHA="$(sha256sum "$BATCH_PROD" | awk '{print $1}')"
CURRENT_POLICY_SHA="$(sha256sum "$POLICY_PROD" | awk '{print $1}')"

echo "STAGE_BUILDER_SHA=$STAGE_BUILDER_SHA"
echo "STAGE_RESET_SHA=$STAGE_RESET_SHA"
echo "CURRENT_BUILDER_SHA=$CURRENT_BUILDER_SHA"
echo "CURRENT_BATCH_SHA=$CURRENT_BATCH_SHA"
echo "CURRENT_POLICY_SHA=$CURRENT_POLICY_SHA"

[[ "$STAGE_BUILDER_SHA" == "$NEW_BUILDER_SHA" ]] || { echo "ERROR=Staged builder SHA mismatch." >&2; exit 1; }
[[ "$STAGE_RESET_SHA" == "$RESET_SHA" ]] || { echo "ERROR=Reset helper SHA mismatch." >&2; exit 1; }
[[ "$CURRENT_BATCH_SHA" == "$EXPECTED_BATCH_SHA" ]] || { echo "ERROR=Eporner batch SHA changed unexpectedly." >&2; exit 1; }
[[ "$CURRENT_POLICY_SHA" == "$EXPECTED_POLICY_SHA" ]] || { echo "ERROR=Video policy SHA changed unexpectedly." >&2; exit 1; }

if [[ "$CURRENT_BUILDER_SHA" != "$OLD_BUILDER_SHA" && "$CURRENT_BUILDER_SHA" != "$NEW_BUILDER_SHA" ]]; then
    echo "ERROR=Production Eporner builder has unexpected SHA." >&2
    exit 1
fi

echo "EPORNER_VIEWS_V1_1_PRECHECK=PASS"

echo
echo "===== BACKUP ====="
mkdir -p "$BACKUP"
cp -a "$BUILDER_PROD" "$BACKUP/eporner-csv-builder.py"
echo "BACKUP_DIR=$BACKUP"
echo "EPORNER_VIEWS_V1_1_BACKUP=PASS"

echo
echo "===== INSTALL BUILDER ====="
install -m 0755 "$BUILDER_STAGE" "$BUILDER_PROD"
python3 -m py_compile "$BUILDER_PROD"
INSTALLED_SHA="$(sha256sum "$BUILDER_PROD" | awk '{print $1}')"
echo "INSTALLED_BUILDER_SHA=$INSTALLED_SHA"
[[ "$INSTALLED_SHA" == "$NEW_BUILDER_SHA" ]] || { echo "ERROR=Installed builder SHA mismatch." >&2; exit 1; }
echo "EPORNER_BUILDER_VIEW_ZERO_INSTALL=PASS"

echo
echo "===== RESET EXISTING EPORNER VIEWS ONLY ====="
php "$RESET_STAGE" \
    --backend="$BACKEND" \
    --backup="$BACKUP/eporner-views-before-reset.csv"

echo
echo "===== PREPARE-ONLY BUILDER SMOKE ====="
php "$BATCH_PROD" amateur 1 --prepare-only --target=5 --max-pages=1

SMOKE_CSV="$OPS/batches/amateur-1-v8/xurvexa-eporner-amateur-1.csv"
if [[ ! -f "$SMOKE_CSV" ]]; then
    echo "ERROR=Smoke CSV missing: $SMOKE_CSV" >&2
    exit 1
fi

python3 - "$SMOKE_CSV" <<'PY'
import csv
import sys
from pathlib import Path

path = Path(sys.argv[1])
with path.open('r', encoding='utf-8-sig', newline='') as handle:
    rows = list(csv.DictReader(handle))

if not rows:
    raise SystemExit('ERROR=Smoke CSV has no data rows.')

bad = [row.get('slug', '') for row in rows if str(row.get('views', '')).strip() != '0']
print(f'SMOKE_VIDEO_ROWS={len(rows)}')
print(f'SMOKE_NONZERO_VIEW_ROWS={len(bad)}')
if bad:
    print('ERROR=Smoke CSV contains non-zero Xurvexa views: ' + ','.join(bad[:10]), file=sys.stderr)
    raise SystemExit(1)
print('EPORNER_NEW_VIDEO_VIEW_ZERO_SMOKE=PASS')
PY

echo
echo "===== FINAL DB CHECK ====="
cd "$BACKEND"
php artisan tinker --execute='
use App\Models\Video;
use Illuminate\Support\Facades\DB;

echo "TOTAL_VIDEOS=" . Video::query()->count() . PHP_EOL;
echo "EPORNER_VIDEOS=" . Video::query()->where("video_source", "eporner")->count() . PHP_EOL;
echo "EPORNER_NONZERO_VIEWS=" . Video::query()->where("video_source", "eporner")->where("views", "!=", 0)->count() . PHP_EOL;
echo "EPORNER_VIEW_SUM=" . (int) Video::query()->where("video_source", "eporner")->sum("views") . PHP_EOL;
echo "ORPHANS=" . DB::table("video_source_terms as vst")->leftJoin("videos as v", "v.id", "=", "vst.video_id")->whereNull("v.id")->count() . PHP_EOL;
'

echo "EPORNER_VIEWS_V1_1_DEPLOY=PASS"
