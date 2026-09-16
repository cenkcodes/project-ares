#!/usr/bin/env bash
set -euo pipefail

STAGE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="/var/www/project-ares/ops/video-import"
BACKEND="/var/www/project-ares/backend"
EXPECTED_STAGE_COLLECTOR="0b67be80670a2abe4a76a90cd982abfc618627060a03e92b58a787b5d6de6d6d"
EXPECTED_STAGE_BUILDER="28b2d0c7cf449a06f0a8634380f1c44912250f8ebb1a21c0b5c6c59818e42f46"
EXPECTED_CURRENT_COLLECTOR="94e27c4e388941f98bceb5dc77d9777fffb0cc1eb3da082ea7fa399936b21410"
EXPECTED_CURRENT_BUILDER="de17855829272ea77bc0e67afd8bb179593a19e201ffcd9d678748473b60331d"
EXPECTED_BATCH="6af038866edf286657a5eae5049ba275b31c82984f183573b53acd12a7f8c72c"
EXPECTED_POLICY="9f71752390a9b6101f51cdfa8e2ddad56fe1e364294256e59bc80ef40aa3fb52"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$ROOT/backups/eporner-ts-guard-v1-3-$TIMESTAMP"
INSTALLED=0

sha() { sha256sum "$1" | awk '{print $1}'; }
fail() { echo "ERROR=$*" >&2; exit 1; }

rollback() {
    local code=$?
    if [[ $code -ne 0 && $INSTALLED -eq 1 && -d "$BACKUP_DIR" ]]; then
        echo
        echo "===== AUTOMATIC FILE ROLLBACK ====="
        cp -f "$BACKUP_DIR/eporner-api-collector.py" "$ROOT/eporner-api-collector.py" || true
        cp -f "$BACKUP_DIR/eporner-csv-builder.py" "$ROOT/eporner-csv-builder.py" || true
        chmod 0755 "$ROOT/eporner-api-collector.py" "$ROOT/eporner-csv-builder.py" || true
        echo "EPORNER_TS_GUARD_FILE_ROLLBACK=PASS"
    fi
    exit $code
}
trap rollback EXIT

echo "===== EPORNER TS GUARD V1.3 PACKAGE PRECHECK ====="
[[ "$(sha "$STAGE_DIR/eporner-api-collector.py")" == "$EXPECTED_STAGE_COLLECTOR" ]] || fail "Stage collector hash mismatch"
[[ "$(sha "$STAGE_DIR/eporner-csv-builder.py")" == "$EXPECTED_STAGE_BUILDER" ]] || fail "Stage builder hash mismatch"
echo "STAGE_COLLECTOR_SHA=$(sha "$STAGE_DIR/eporner-api-collector.py")"
echo "STAGE_BUILDER_SHA=$(sha "$STAGE_DIR/eporner-csv-builder.py")"
echo "EPORNER_TS_GUARD_V1_3_PACKAGE_PRECHECK=PASS"

echo
echo "===== PROTECTED ARCHITECTURE CHECK ====="
[[ "$(sha "$ROOT/eporner-api-collector.py")" == "$EXPECTED_CURRENT_COLLECTOR" ]] || fail "Current Eporner collector drifted"
[[ "$(sha "$ROOT/eporner-csv-builder.py")" == "$EXPECTED_CURRENT_BUILDER" ]] || fail "Current Eporner builder drifted"
[[ "$(sha "$ROOT/eporner-batch.php")" == "$EXPECTED_BATCH" ]] || fail "Eporner batch drifted"
[[ "$(sha "$ROOT/video_import_policy.py")" == "$EXPECTED_POLICY" ]] || fail "Global V8 policy drifted"
echo "CURRENT_COLLECTOR_SHA=$(sha "$ROOT/eporner-api-collector.py")"
echo "CURRENT_BUILDER_SHA=$(sha "$ROOT/eporner-csv-builder.py")"
echo "EPORNER_BATCH_SHA=$(sha "$ROOT/eporner-batch.php")"
echo "VIDEO_POLICY_SHA=$(sha "$ROOT/video_import_policy.py")"
echo "PROTECTED_ARCHITECTURE_CHECK=PASS"

echo
echo "===== PRE-PATCH DB SNAPSHOT ====="
cd "$BACKEND"
BEFORE="$(php artisan tinker --execute='use App\Models\Video; use Illuminate\Support\Facades\DB; echo Video::query()->count()."|".Video::query()->where("video_source","eporner")->count()."|".DB::table("video_source_terms")->count()."|".DB::table("video_source_terms as vst")->leftJoin("videos as v","v.id","=","vst.video_id")->whereNull("v.id")->count();' | tail -n 1)"
IFS='|' read -r BEFORE_TOTAL BEFORE_EPORNER BEFORE_TERMS BEFORE_ORPHANS <<< "$BEFORE"
echo "TOTAL_VIDEOS_BEFORE=$BEFORE_TOTAL"
echo "EPORNER_VIDEOS_BEFORE=$BEFORE_EPORNER"
echo "SOURCE_TERMS_BEFORE=$BEFORE_TERMS"
echo "ORPHANS_BEFORE=$BEFORE_ORPHANS"
[[ "$BEFORE_ORPHANS" == "0" ]] || fail "Pre-patch orphans detected"

echo
echo "===== BACKUP ====="
mkdir -p "$BACKUP_DIR"
cp -a "$ROOT/eporner-api-collector.py" "$BACKUP_DIR/eporner-api-collector.py"
cp -a "$ROOT/eporner-csv-builder.py" "$BACKUP_DIR/eporner-csv-builder.py"
echo "BACKUP_DIR=$BACKUP_DIR"
echo "EPORNER_TS_GUARD_V1_3_BACKUP=PASS"

echo
echo "===== INSTALL EPORNER PROVIDER GUARD ONLY ====="
cp -f "$STAGE_DIR/eporner-api-collector.py" "$ROOT/eporner-api-collector.py"
cp -f "$STAGE_DIR/eporner-csv-builder.py" "$ROOT/eporner-csv-builder.py"
chmod 0755 "$ROOT/eporner-api-collector.py" "$ROOT/eporner-csv-builder.py"
INSTALLED=1
python3 -m py_compile "$ROOT/eporner-api-collector.py" "$ROOT/eporner-csv-builder.py"
[[ "$(sha "$ROOT/eporner-api-collector.py")" == "$EXPECTED_STAGE_COLLECTOR" ]] || fail "Installed collector hash mismatch"
[[ "$(sha "$ROOT/eporner-csv-builder.py")" == "$EXPECTED_STAGE_BUILDER" ]] || fail "Installed builder hash mismatch"
echo "INSTALLED_COLLECTOR_SHA=$(sha "$ROOT/eporner-api-collector.py")"
echo "INSTALLED_BUILDER_SHA=$(sha "$ROOT/eporner-csv-builder.py")"
echo "EPORNER_TS_GUARD_INSTALL=PASS"

echo
echo "===== PROVIDER GUARD SELF TEST ====="
cd "$ROOT"
python3 - <<'PY'
import importlib.util
import sys
from pathlib import Path

root = Path("/var/www/project-ares/ops/video-import")
sys.path.insert(0, str(root))

def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module

collector = load("eporner_collector_guard_test", root / "eporner-api-collector.py")
builder = load("eporner_builder_guard_test", root / "eporner-csv-builder.py")

blocked_search = collector.blocked_reason({
    "title": "Post Op TS Superstar Intense Scene",
    "keywords": "milf, webcam",
    "url": "https://example.invalid/video",
})
if blocked_search is None or "standalone-ts" not in blocked_search:
    raise SystemExit("Collector failed standalone TS fixture")

metadata = builder.VideoMetadata(
    video_id="Fixture123",
    title="Post Op TS Superstar Intense Scene",
    description="",
    embed_url="https://www.eporner.com/embed/Fixture123/",
    thumbnail="https://example.invalid/thumb.jpg",
    duration=60,
    views=0,
    tags=("milf",),
)
blocked_detail = builder.metadata_policy_reason(metadata)
if blocked_detail is None or "standalone-ts" not in blocked_detail:
    raise SystemExit("Builder failed standalone TS fixture")

for benign in ("cats", "tshirt", "tests", "milf"): 
    if collector.eporner_editorial_reason(benign) is not None:
        raise SystemExit(f"Collector false positive: {benign}")
    if builder.eporner_editorial_reason(benign) is not None:
        raise SystemExit(f"Builder false positive: {benign}")

print("COLLECTOR_TS_FIXTURE=PASS")
print("BUILDER_TS_FIXTURE=PASS")
print("BENIGN_TOKEN_FIXTURES=PASS")
print("EPORNER_TS_GUARD_SELF_TEST=PASS")
PY

echo
echo "===== MILF PREPARE-ONLY SMOKE ====="
php "$ROOT/eporner-batch.php" milf 1 --prepare-only --target=50 --max-pages=1

CSV="$ROOT/batches/milf-1-v8/xurvexa-eporner-milf-1.csv"
[[ -f "$CSV" ]] || fail "MILF smoke CSV missing"
python3 - "$CSV" <<'PY'
import csv
import re
import sys

pattern = re.compile(r"(?<![a-z0-9])ts(?![a-z0-9])", re.I)
hits = []
with open(sys.argv[1], "r", encoding="utf-8-sig", newline="") as handle:
    for row in csv.DictReader(handle):
        text = " ".join((row.get("title") or "", row.get("description") or ""))
        if pattern.search(text):
            hits.append((row.get("slug", ""), row.get("title", "")))
print(f"SMOKE_STANDALONE_TS_ROWS={len(hits)}")
for slug, title in hits[:20]:
    print(f"SMOKE_TS_HIT={slug}|{title}")
if hits:
    raise SystemExit("Standalone TS survived into prepared canonical CSV")
print("EPORNER_MILF_TS_GUARD_SMOKE=PASS")
PY

echo
echo "===== FINAL DB UNCHANGED CHECK ====="
cd "$BACKEND"
AFTER="$(php artisan tinker --execute='use App\Models\Video; use Illuminate\Support\Facades\DB; echo Video::query()->count()."|".Video::query()->where("video_source","eporner")->count()."|".DB::table("video_source_terms")->count()."|".DB::table("video_source_terms as vst")->leftJoin("videos as v","v.id","=","vst.video_id")->whereNull("v.id")->count();' | tail -n 1)"
IFS='|' read -r AFTER_TOTAL AFTER_EPORNER AFTER_TERMS AFTER_ORPHANS <<< "$AFTER"
echo "TOTAL_VIDEOS_AFTER=$AFTER_TOTAL"
echo "EPORNER_VIDEOS_AFTER=$AFTER_EPORNER"
echo "SOURCE_TERMS_AFTER=$AFTER_TERMS"
echo "ORPHANS_AFTER=$AFTER_ORPHANS"
[[ "$AFTER_TOTAL" == "$BEFORE_TOTAL" ]] || fail "Video count changed during prepare-only patch"
[[ "$AFTER_EPORNER" == "$BEFORE_EPORNER" ]] || fail "Eporner count changed during prepare-only patch"
[[ "$AFTER_TERMS" == "$BEFORE_TERMS" ]] || fail "Source-term count changed during prepare-only patch"
[[ "$AFTER_ORPHANS" == "0" ]] || fail "Orphans detected after patch"

echo "EPORNER_TS_GUARD_V1_3_DB_UNCHANGED=PASS"
echo "EPORNER_TS_GUARD_V1_3_DEPLOY=PASS"
INSTALLED=0
trap - EXIT
