#!/usr/bin/env bash
set -euo pipefail

STAGE="${1:-}"
OPS="/var/www/project-ares/ops/video-import"
BACKEND="/var/www/project-ares/backend"
BACKUPS="$OPS/backups"

if [[ -z "$STAGE" || ! -d "$STAGE" ]]; then
    echo "ERROR=Valid stage directory argument is required." >&2
    exit 2
fi

COLLECTOR="$OPS/eporner-api-collector.py"
BUILDER="$OPS/eporner-csv-builder.py"
BATCH="$OPS/eporner-batch.php"
BOOTSTRAP="$OPS/eporner-provider-bootstrap.php"

EXPECTED_STAGE_COLLECTOR="94e27c4e388941f98bceb5dc77d9777fffb0cc1eb3da082ea7fa399936b21410"
EXPECTED_STAGE_BUILDER="0f929cba3e3bf6c7a84bf5af28bef711186850f8287426a768ed4b0005ac4eba"
EXPECTED_STAGE_BATCH="6af038866edf286657a5eae5049ba275b31c82984f183573b53acd12a7f8c72c"
EXPECTED_STAGE_BOOTSTRAP="741a567e17793f980e77e8c06c048ef23729bbd0c8836201918696bf475509a7"

EXPECTED_XVIDEOS_BATCH="876b668171dd1744a7887390121edbe32522673d6cebefef6d1ebd8fa201d3a7"
EXPECTED_XVIDEOS_COLLECTOR="000796a3cef3f03eeea041840f9fc0ff8cafee6540baaaf057c5a1b1822cff70"
EXPECTED_XVIDEOS_BUILDER="338532ef065106df18f5bb52f5c20d4f63bc71bc68366545bc96791f1a4dafc9"
EXPECTED_POLICY="9f71752390a9b6101f51cdfa8e2ddad56fe1e364294256e59bc80ef40aa3fb52"
EXPECTED_SOURCE_IMPORTER="722d23cb06c72a3fd1ce347a8f5827f954dc37357cce64c839fcb93ab8bbf0d8"
EXPECTED_VIDEO_IMPORTER="67a8cc1584efc72801ab83b9ad7d8194ddb5d8c4a4189679553248fb49b9dcd4"
EXPECTED_VIDEO_MODEL="887d505cba4ab63864ba4a164ad1df814e6ff1c26349ed54f9dc31d1b1bba135"
EXPECTED_PROVIDER_MODEL="5f1b58072f117922a98f581d84da982b2e27cbf5a3bdfb434b010a2d4bc21968"

sha_of() {
    sha256sum "$1" | awk '{print $1}'
}

require_hash() {
    local path="$1"
    local expected="$2"
    local label="$3"

    if [[ ! -f "$path" ]]; then
        echo "ERROR=$label missing: $path" >&2
        exit 1
    fi

    local actual
    actual="$(sha_of "$path")"
    echo "$label=$actual"

    if [[ "$actual" != "$expected" ]]; then
        echo "ERROR=$label hash mismatch. Expected $expected got $actual" >&2
        exit 1
    fi
}

restore_files() {
    set +e

    echo
    echo "===== EPORNER V1 ROLLBACK ====="

    if [[ "${PROVIDER_BEFORE:-0}" == "0" && -f "$STAGE/eporner-provider-bootstrap.php" ]]; then
        php "$STAGE/eporner-provider-bootstrap.php" \
            --backend="$BACKEND" \
            --rollback-created || true
    fi

    for name in \
        eporner-api-collector.py \
        eporner-csv-builder.py \
        eporner-batch.php \
        eporner-provider-bootstrap.php
    do
        target="$OPS/$name"
        backup="$BACKUP_DIR/$name"

        if [[ -f "$backup" ]]; then
            cp -a "$backup" "$target"
            echo "RESTORED=$target"
        else
            rm -f "$target"
            echo "REMOVED_NEW_FILE=$target"
        fi
    done

    echo "EPORNER_PROVIDER_V1_ROLLBACK=PASS"
}

on_exit() {
    local status=$?
    if [[ $status -ne 0 && "${DEPLOY_STARTED:-0}" == "1" ]]; then
        restore_files
    fi
    exit $status
}
trap on_exit EXIT

for staged in \
    eporner-api-collector.py \
    eporner-csv-builder.py \
    eporner-batch.php \
    eporner-provider-bootstrap.php
 do
    if [[ ! -f "$STAGE/$staged" ]]; then
        echo "ERROR=Staged payload missing: $STAGE/$staged" >&2
        exit 1
    fi
 done

echo "===== EPORNER V1 STAGE HASHES ====="
require_hash "$STAGE/eporner-api-collector.py" "$EXPECTED_STAGE_COLLECTOR" "STAGE_COLLECTOR_SHA"
require_hash "$STAGE/eporner-csv-builder.py" "$EXPECTED_STAGE_BUILDER" "STAGE_BUILDER_SHA"
require_hash "$STAGE/eporner-batch.php" "$EXPECTED_STAGE_BATCH" "STAGE_BATCH_SHA"
require_hash "$STAGE/eporner-provider-bootstrap.php" "$EXPECTED_STAGE_BOOTSTRAP" "STAGE_PROVIDER_BOOTSTRAP_SHA"
echo "EPORNER_V1_PACKAGE_PRECHECK=PASS"

echo
echo "===== PROTECTED ARCHITECTURE HASH CHECK ====="
require_hash "$OPS/xvideos-batch.php" "$EXPECTED_XVIDEOS_BATCH" "XVIDEOS_BATCH_SHA"
require_hash "$OPS/xvideos-url-collector.py" "$EXPECTED_XVIDEOS_COLLECTOR" "XVIDEOS_COLLECTOR_SHA"
require_hash "$OPS/xvideos-csv-builder.py" "$EXPECTED_XVIDEOS_BUILDER" "XVIDEOS_BUILDER_SHA"
require_hash "$OPS/video_import_policy.py" "$EXPECTED_POLICY" "VIDEO_POLICY_SHA"
require_hash "$OPS/import-video-source-terms.php" "$EXPECTED_SOURCE_IMPORTER" "SOURCE_IMPORTER_SHA"
require_hash "$BACKEND/app/Filament/Imports/VideoImporter.php" "$EXPECTED_VIDEO_IMPORTER" "VIDEO_IMPORTER_SHA"
require_hash "$BACKEND/app/Models/Video.php" "$EXPECTED_VIDEO_MODEL" "VIDEO_MODEL_SHA"
require_hash "$BACKEND/app/Models/VideoProvider.php" "$EXPECTED_PROVIDER_MODEL" "VIDEO_PROVIDER_MODEL_SHA"

echo
echo "===== PRODUCTION DB PRECHECK ====="
DB_PRECHECK="$(cd "$BACKEND" && php artisan tinker --execute='
use Illuminate\Support\Facades\DB;

echo "TOTAL_VIDEOS=" . DB::table("videos")->count() . PHP_EOL;
echo "ACTIVE_VIDEOS=" . DB::table("videos")->where("is_active", true)->count() . PHP_EOL;
echo "XVIDEOS_VIDEOS=" . DB::table("videos")->where("video_source", "xvideos")->count() . PHP_EOL;
echo "EPORNER_VIDEOS=" . DB::table("videos")->where("video_source", "eporner")->count() . PHP_EOL;
echo "ACTIVE_CATEGORIES=" . DB::table("categories")->where("is_active", true)->count() . PHP_EOL;
echo "SOURCE_TERMS=" . DB::table("video_source_terms")->count() . PHP_EOL;
echo "ORPHANS=" . DB::table("video_source_terms as vst")
    ->leftJoin("videos as v", "v.id", "=", "vst.video_id")
    ->whereNull("v.id")
    ->count() . PHP_EOL;
echo "EPORNER_PROVIDER_ROWS=" . DB::table("video_providers")->where("slug", "eporner")->count() . PHP_EOL;
')"
echo "$DB_PRECHECK"

value_of() {
    local key="$1"
    printf '%s\n' "$DB_PRECHECK" | sed -n "s/^${key}=//p" | tail -n 1
}

[[ "$(value_of TOTAL_VIDEOS)" == "15455" ]] || { echo "ERROR=Unexpected TOTAL_VIDEOS baseline." >&2; exit 1; }
[[ "$(value_of ACTIVE_VIDEOS)" == "15455" ]] || { echo "ERROR=Unexpected ACTIVE_VIDEOS baseline." >&2; exit 1; }
[[ "$(value_of XVIDEOS_VIDEOS)" == "15455" ]] || { echo "ERROR=Unexpected XVideos baseline." >&2; exit 1; }
[[ "$(value_of EPORNER_VIDEOS)" == "0" ]] || { echo "ERROR=Eporner videos already exist; V1 initial deployment aborted." >&2; exit 1; }
[[ "$(value_of ACTIVE_CATEGORIES)" == "25" ]] || { echo "ERROR=Unexpected active category baseline." >&2; exit 1; }
[[ "$(value_of SOURCE_TERMS)" == "223528" ]] || { echo "ERROR=Unexpected source-term baseline." >&2; exit 1; }
[[ "$(value_of ORPHANS)" == "0" ]] || { echo "ERROR=Orphan source terms detected." >&2; exit 1; }

PROVIDER_BEFORE="$(value_of EPORNER_PROVIDER_ROWS)"
if [[ "$PROVIDER_BEFORE" != "0" && "$PROVIDER_BEFORE" != "1" ]]; then
    echo "ERROR=Unexpected Eporner provider row count: $PROVIDER_BEFORE" >&2
    exit 1
fi

echo "PRODUCTION_PRECHECK=PASS"

echo
echo "===== BACKUP ====="
mkdir -p "$BACKUPS"
BACKUP_DIR="$BACKUPS/eporner-provider-v1-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"

for name in \
    eporner-api-collector.py \
    eporner-csv-builder.py \
    eporner-batch.php \
    eporner-provider-bootstrap.php
 do
    if [[ -f "$OPS/$name" ]]; then
        cp -a "$OPS/$name" "$BACKUP_DIR/$name"
    fi
 done

echo "BACKUP_DIR=$BACKUP_DIR"
echo "EPORNER_V1_BACKUP=PASS"

DEPLOY_STARTED=1

echo
echo "===== INSTALL NEW EPORNER FILES ONLY ====="
install -m 0755 "$STAGE/eporner-api-collector.py" "$COLLECTOR"
install -m 0755 "$STAGE/eporner-csv-builder.py" "$BUILDER"
install -m 0755 "$STAGE/eporner-batch.php" "$BATCH"
install -m 0755 "$STAGE/eporner-provider-bootstrap.php" "$BOOTSTRAP"

require_hash "$COLLECTOR" "$EXPECTED_STAGE_COLLECTOR" "INSTALLED_COLLECTOR_SHA"
require_hash "$BUILDER" "$EXPECTED_STAGE_BUILDER" "INSTALLED_BUILDER_SHA"
require_hash "$BATCH" "$EXPECTED_STAGE_BATCH" "INSTALLED_BATCH_SHA"
require_hash "$BOOTSTRAP" "$EXPECTED_STAGE_BOOTSTRAP" "INSTALLED_PROVIDER_BOOTSTRAP_SHA"

python3 -c 'import pathlib; compile(pathlib.Path("/var/www/project-ares/ops/video-import/eporner-api-collector.py").read_text(), "eporner-api-collector.py", "exec")'
python3 -c 'import pathlib; compile(pathlib.Path("/var/www/project-ares/ops/video-import/eporner-csv-builder.py").read_text(), "eporner-csv-builder.py", "exec")'
php -l "$BATCH"
php -l "$BOOTSTRAP"
echo "EPORNER_V1_FILE_INSTALL=PASS"

echo
echo "===== PROVIDER ROW ====="
php "$BOOTSTRAP" --backend="$BACKEND" --install

echo
echo "===== PREPARE-ONLY END-TO-END SMOKE ====="
SMOKE_OUTPUT="$($BATCH amateur 1 --prepare-only --target=20 --max-pages=3 --delay=0.05 2>&1)"
echo "$SMOKE_OUTPUT"
printf '%s\n' "$SMOKE_OUTPUT" | grep -q '^COLLECTOR_VALIDATION=PASS$'
printf '%s\n' "$SMOKE_OUTPUT" | grep -q '^BUILDER_VALIDATION=PASS$'
printf '%s\n' "$SMOKE_OUTPUT" | grep -q '^BATCH_STRUCTURE_VALIDATION=PASS$'
printf '%s\n' "$SMOKE_OUTPUT" | grep -q '^BATCH_PREPARATION=PASS$'
echo "EPORNER_PREPARE_ONLY_SMOKE=PASS"

echo
echo "===== FINAL DB INTEGRITY ====="
DB_FINAL="$(cd "$BACKEND" && php artisan tinker --execute='
use Illuminate\Support\Facades\DB;

echo "TOTAL_VIDEOS=" . DB::table("videos")->count() . PHP_EOL;
echo "ACTIVE_VIDEOS=" . DB::table("videos")->where("is_active", true)->count() . PHP_EOL;
echo "XVIDEOS_VIDEOS=" . DB::table("videos")->where("video_source", "xvideos")->count() . PHP_EOL;
echo "EPORNER_VIDEOS=" . DB::table("videos")->where("video_source", "eporner")->count() . PHP_EOL;
echo "SOURCE_TERMS=" . DB::table("video_source_terms")->count() . PHP_EOL;
echo "ORPHANS=" . DB::table("video_source_terms as vst")
    ->leftJoin("videos as v", "v.id", "=", "vst.video_id")
    ->whereNull("v.id")
    ->count() . PHP_EOL;
$p = DB::table("video_providers")->where("slug", "eporner")->first();
echo "EPORNER_PROVIDER_EXISTS=" . ($p ? "1" : "0") . PHP_EOL;
if ($p) {
    echo "EPORNER_PROVIDER_ACTIVE=" . ((int) $p->is_active) . PHP_EOL;
    echo "EPORNER_PROVIDER_MONETIZATION=" . ((int) $p->monetization_enabled) . PHP_EOL;
    echo "EPORNER_PROVIDER_PREROLL=" . ((int) $p->allow_xurvexa_preroll) . PHP_EOL;
    echo "EPORNER_PROVIDER_MIDROLL=" . ((int) $p->allow_xurvexa_midroll) . PHP_EOL;
    echo "EPORNER_PROVIDER_POPUNDER=" . ((int) $p->allow_popunder) . PHP_EOL;
    echo "EPORNER_PROVIDER_NATIVE=" . ((int) $p->allow_native_ads) . PHP_EOL;
    echo "EPORNER_PROVIDER_BANNER=" . ((int) $p->allow_banner_ads) . PHP_EOL;
    echo "EPORNER_PROVIDER_INTERSTITIAL=" . ((int) $p->allow_interstitial) . PHP_EOL;
}
')"
echo "$DB_FINAL"

final_value() {
    local key="$1"
    printf '%s\n' "$DB_FINAL" | sed -n "s/^${key}=//p" | tail -n 1
}

[[ "$(final_value TOTAL_VIDEOS)" == "15455" ]] || { echo "ERROR=Final total videos changed during prepare-only deploy." >&2; exit 1; }
[[ "$(final_value ACTIVE_VIDEOS)" == "15455" ]] || { echo "ERROR=Final active videos changed during prepare-only deploy." >&2; exit 1; }
[[ "$(final_value XVIDEOS_VIDEOS)" == "15455" ]] || { echo "ERROR=Final XVideos count changed." >&2; exit 1; }
[[ "$(final_value EPORNER_VIDEOS)" == "0" ]] || { echo "ERROR=Prepare-only deployment unexpectedly imported Eporner videos." >&2; exit 1; }
[[ "$(final_value SOURCE_TERMS)" == "223528" ]] || { echo "ERROR=Source-term count changed during prepare-only deploy." >&2; exit 1; }
[[ "$(final_value ORPHANS)" == "0" ]] || { echo "ERROR=Orphans detected after deployment." >&2; exit 1; }
[[ "$(final_value EPORNER_PROVIDER_EXISTS)" == "1" ]] || { echo "ERROR=Eporner provider row missing after bootstrap." >&2; exit 1; }
[[ "$(final_value EPORNER_PROVIDER_ACTIVE)" == "1" ]] || { echo "ERROR=Eporner provider is not active." >&2; exit 1; }
[[ "$(final_value EPORNER_PROVIDER_MONETIZATION)" == "0" ]] || { echo "ERROR=Eporner monetization must remain disabled in V1." >&2; exit 1; }
[[ "$(final_value EPORNER_PROVIDER_PREROLL)" == "0" ]] || { echo "ERROR=Eporner preroll must remain disabled in V1." >&2; exit 1; }
[[ "$(final_value EPORNER_PROVIDER_MIDROLL)" == "0" ]] || { echo "ERROR=Eporner midroll must remain disabled in V1." >&2; exit 1; }
[[ "$(final_value EPORNER_PROVIDER_POPUNDER)" == "0" ]] || { echo "ERROR=Eporner popunder must remain disabled in V1." >&2; exit 1; }
[[ "$(final_value EPORNER_PROVIDER_NATIVE)" == "0" ]] || { echo "ERROR=Eporner native ads must remain disabled in V1." >&2; exit 1; }
[[ "$(final_value EPORNER_PROVIDER_BANNER)" == "0" ]] || { echo "ERROR=Eporner banner must remain disabled in V1." >&2; exit 1; }
[[ "$(final_value EPORNER_PROVIDER_INTERSTITIAL)" == "0" ]] || { echo "ERROR=Eporner interstitial must remain disabled in V1." >&2; exit 1; }

echo "EPORNER_V1_FINAL_DB_INTEGRITY=PASS"
echo "EPORNER_PROVIDER_V1_DEPLOY=PASS"

DEPLOY_STARTED=0
trap - EXIT
