#!/usr/bin/env bash
set -euo pipefail

STAGE="${1:-}"
OPS="/var/www/project-ares/ops/video-import"
BACKEND="/var/www/project-ares/backend"
OLD_BUILDER_SHA="fb83e94ca78ac66f364054d4f7d332f2b4befcf16b1991bb68df70329e2d9e3f"
NEW_BUILDER_SHA="de17855829272ea77bc0e67afd8bb179593a19e201ffcd9d678748473b60331d"
VERIFY_SHA="05170bbc91f41079290061f207f1de6c5b8adc4d84437cc44362cfce16bda17a"
EXPECTED_BATCH_SHA="6af038866edf286657a5eae5049ba275b31c82984f183573b53acd12a7f8c72c"
EXPECTED_POLICY_SHA="9f71752390a9b6101f51cdfa8e2ddad56fe1e364294256e59bc80ef40aa3fb52"
EXPECTED_SOURCE_IMPORTER_SHA="722d23cb06c72a3fd1ce347a8f5827f954dc37357cce64c839fcb93ab8bbf0d8"
EXPECTED_RESOLVER_SHA="14399190be0ff5a165b200f6fe7e45812492129983bc4c340726bbc7072f4268"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$OPS/backups/eporner-normalization-v1-2-$STAMP"

if [[ -z "$STAGE" || ! -d "$STAGE" ]]; then
    echo "ERROR=Stage directory missing." >&2
    exit 1
fi

BUILDER_STAGE="$STAGE/eporner-csv-builder.py"
VERIFY_STAGE="$STAGE/verify-source-term-normalization.php"
BUILDER_PROD="$OPS/eporner-csv-builder.py"
BATCH_PROD="$OPS/eporner-batch.php"
POLICY_PROD="$OPS/video_import_policy.py"
SOURCE_IMPORTER_PROD="$OPS/import-video-source-terms.php"
RESOLVER_PROD="$BACKEND/app/Services/VideoTaxonomyResolver.php"

for f in "$BUILDER_STAGE" "$VERIFY_STAGE" "$BUILDER_PROD" "$BATCH_PROD" "$POLICY_PROD" "$SOURCE_IMPORTER_PROD" "$RESOLVER_PROD"; do
    if [[ ! -f "$f" ]]; then
        echo "ERROR=Required file missing: $f" >&2
        exit 1
    fi
done

echo "===== EPORNER NORMALIZATION V1.2 PACKAGE PRECHECK ====="
STAGE_BUILDER_SHA="$(sha256sum "$BUILDER_STAGE" | awk '{print $1}')"
STAGE_VERIFY_SHA="$(sha256sum "$VERIFY_STAGE" | awk '{print $1}')"
CURRENT_BUILDER_SHA="$(sha256sum "$BUILDER_PROD" | awk '{print $1}')"
CURRENT_BATCH_SHA="$(sha256sum "$BATCH_PROD" | awk '{print $1}')"
CURRENT_POLICY_SHA="$(sha256sum "$POLICY_PROD" | awk '{print $1}')"
CURRENT_SOURCE_IMPORTER_SHA="$(sha256sum "$SOURCE_IMPORTER_PROD" | awk '{print $1}')"
CURRENT_RESOLVER_SHA="$(sha256sum "$RESOLVER_PROD" | awk '{print $1}')"

echo "STAGE_BUILDER_SHA=$STAGE_BUILDER_SHA"
echo "STAGE_VERIFY_SHA=$STAGE_VERIFY_SHA"
echo "CURRENT_BUILDER_SHA=$CURRENT_BUILDER_SHA"
echo "CURRENT_BATCH_SHA=$CURRENT_BATCH_SHA"
echo "CURRENT_POLICY_SHA=$CURRENT_POLICY_SHA"
echo "CURRENT_SOURCE_IMPORTER_SHA=$CURRENT_SOURCE_IMPORTER_SHA"
echo "CURRENT_RESOLVER_SHA=$CURRENT_RESOLVER_SHA"

[[ "$STAGE_BUILDER_SHA" == "$NEW_BUILDER_SHA" ]] || { echo "ERROR=Staged builder SHA mismatch." >&2; exit 1; }
[[ "$STAGE_VERIFY_SHA" == "$VERIFY_SHA" ]] || { echo "ERROR=Staged verifier SHA mismatch." >&2; exit 1; }
[[ "$CURRENT_BUILDER_SHA" == "$OLD_BUILDER_SHA" ]] || { echo "ERROR=Production Eporner builder SHA changed unexpectedly." >&2; exit 1; }
[[ "$CURRENT_BATCH_SHA" == "$EXPECTED_BATCH_SHA" ]] || { echo "ERROR=Eporner batch SHA changed unexpectedly." >&2; exit 1; }
[[ "$CURRENT_POLICY_SHA" == "$EXPECTED_POLICY_SHA" ]] || { echo "ERROR=Video policy SHA changed unexpectedly." >&2; exit 1; }
[[ "$CURRENT_SOURCE_IMPORTER_SHA" == "$EXPECTED_SOURCE_IMPORTER_SHA" ]] || { echo "ERROR=Source-term importer SHA changed unexpectedly." >&2; exit 1; }
[[ "$CURRENT_RESOLVER_SHA" == "$EXPECTED_RESOLVER_SHA" ]] || { echo "ERROR=VideoTaxonomyResolver SHA changed unexpectedly." >&2; exit 1; }

echo "EPORNER_NORMALIZATION_V1_2_PRECHECK=PASS"

echo
echo "===== PRE-PATCH DB CHECK ====="
cd "$BACKEND"
php artisan tinker --execute='
use App\Models\Video;
use Illuminate\Support\Facades\DB;

echo "TOTAL_VIDEOS=" . Video::query()->count() . PHP_EOL;
echo "EPORNER_VIDEOS=" . Video::query()->where("video_source", "eporner")->count() . PHP_EOL;
echo "MATURE_VIDEOS=" . Video::query()->whereHas("category", fn ($q) => $q->where("slug", "mature"))->count() . PHP_EOL;
echo "ORPHANS=" . DB::table("video_source_terms as vst")->leftJoin("videos as v", "v.id", "=", "vst.video_id")->whereNull("v.id")->count() . PHP_EOL;
'

echo
echo "===== BACKUP ====="
mkdir -p "$BACKUP"
cp -a "$BUILDER_PROD" "$BACKUP/eporner-csv-builder.py"
echo "BACKUP_DIR=$BACKUP"
echo "EPORNER_NORMALIZATION_V1_2_BACKUP=PASS"

echo
echo "===== INSTALL BUILDER ====="
install -m 0755 "$BUILDER_STAGE" "$BUILDER_PROD"
python3 -m py_compile "$BUILDER_PROD"
INSTALLED_SHA="$(sha256sum "$BUILDER_PROD" | awk '{print $1}')"
echo "INSTALLED_BUILDER_SHA=$INSTALLED_SHA"
[[ "$INSTALLED_SHA" == "$NEW_BUILDER_SHA" ]] || { echo "ERROR=Installed builder SHA mismatch." >&2; exit 1; }
echo "EPORNER_NORMALIZATION_BUILDER_INSTALL=PASS"

echo
echo "===== PREPARE-ONLY MATURE NORMALIZATION SMOKE ====="
php "$BATCH_PROD" mature 1 --prepare-only --target=50 --max-pages=1

SMOKE_TERMS="$OPS/batches/mature-1-v8/xurvexa-eporner-mature-1-source-terms.csv"
if [[ ! -f "$SMOKE_TERMS" ]]; then
    echo "ERROR=Smoke source-term CSV missing: $SMOKE_TERMS" >&2
    exit 1
fi

php "$VERIFY_STAGE" --backend="$BACKEND" --input="$SMOKE_TERMS"
echo "EPORNER_NORMALIZATION_SMOKE=PASS"

echo
echo "===== FINAL DB CHECK ====="
cd "$BACKEND"
php artisan tinker --execute='
use App\Models\Video;
use Illuminate\Support\Facades\DB;

echo "TOTAL_VIDEOS=" . Video::query()->count() . PHP_EOL;
echo "EPORNER_VIDEOS=" . Video::query()->where("video_source", "eporner")->count() . PHP_EOL;
echo "MATURE_VIDEOS=" . Video::query()->whereHas("category", fn ($q) => $q->where("slug", "mature"))->count() . PHP_EOL;
echo "EPORNER_NONZERO_VIEWS=" . Video::query()->where("video_source", "eporner")->where("views", "!=", 0)->count() . PHP_EOL;
echo "ORPHANS=" . DB::table("video_source_terms as vst")->leftJoin("videos as v", "v.id", "=", "vst.video_id")->whereNull("v.id")->count() . PHP_EOL;
'

echo "EPORNER_NORMALIZATION_V1_2_DEPLOY=PASS"
