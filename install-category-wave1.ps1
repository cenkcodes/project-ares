$ErrorActionPreference = "Stop"

$KeyFile = "C:\Users\Pc\.ssh\xurvexa_mojo_rsa"
$Server = "ubuntu@185.94.236.129"

$LocalBatch = "C:\Projects\Project-Ares\ops\video-import\xvideos-batch.php"
$RemoteBatch = "/var/www/project-ares/ops/video-import/xvideos-batch.php"

$ExpectedOldHash = "45f55d2b28fc5052e8a7d9cb3175a5c7acffdeabc22975ffdd0728971f499b25"
$ExpectedNewHash = "dfad3582af6feb14ac233e85b0ff8e613f0fa4549ddca6f3a6cc3816d32c5a6b"

Write-Host ""
Write-Host "===== LOCAL WAVE-1 FILE CHECK ====="

$LocalHash = (Get-FileHash $LocalBatch -Algorithm SHA256).Hash.ToLower()
Write-Host "LOCAL_XVIDEOS_BATCH_SHA256=$LocalHash"

if ($LocalHash -ne $ExpectedNewHash) {
    throw "Local xvideos-batch.php HASH MISMATCH"
}

Write-Host "LOCAL_WAVE1_FILE=PASS"

Write-Host ""
Write-Host "===== PRODUCTION BASELINE / BACKUP ====="

$BaselineScript = @'
set -euo pipefail

REMOTE="/var/www/project-ares/ops/video-import/xvideos-batch.php"
EXPECTED_OLD="45f55d2b28fc5052e8a7d9cb3175a5c7acffdeabc22975ffdd0728971f499b25"

ACTUAL="$(sha256sum "$REMOTE" | awk '{print $1}')"

echo "PRODUCTION_OLD_SHA256=$ACTUAL"

if [ "$ACTUAL" != "$EXPECTED_OLD" ]; then
    echo "ERROR=Production xvideos-batch.php is not the expected baseline."
    exit 1
fi

cp "$REMOTE" "$REMOTE.bak-20260831-category-wave1"

echo "CATEGORY_WAVE1_BACKUP=PASS"
'@

$BaselineScript | ssh -i $KeyFile $Server 'bash -s'

Write-Host ""
Write-Host "===== UPLOAD COMPLETE XVVIDEOS ORCHESTRATOR ====="

scp -i $KeyFile $LocalBatch "${Server}:${RemoteBatch}"

Write-Host ""
Write-Host "===== ORCHESTRATOR VALIDATION ====="

$ValidateScript = @'
set -euo pipefail

REMOTE="/var/www/project-ares/ops/video-import/xvideos-batch.php"
EXPECTED_NEW="dfad3582af6feb14ac233e85b0ff8e613f0fa4549ddca6f3a6cc3816d32c5a6b"

php -l "$REMOTE"

ACTUAL="$(sha256sum "$REMOTE" | awk '{print $1}')"

echo "PRODUCTION_NEW_SHA256=$ACTUAL"

if [ "$ACTUAL" != "$EXPECTED_NEW" ]; then
    echo "ERROR=Production xvideos-batch.php HASH MISMATCH"
    exit 1
fi

for CATEGORY in pov blonde brunette big-tits hardcore lesbian; do
    grep -q "'$CATEGORY'" "$REMOTE"
done

echo "CATEGORY_WAVE1_ORCHESTRATOR=PASS"
'@

$ValidateScript | ssh -i $KeyFile $Server 'bash -s'

Write-Host ""
Write-Host "===== CREATE WAVE-1 CATEGORIES / EXACT ALIASES ====="

$DatabaseScript = @'
set -euo pipefail

cd /var/www/project-ares/backend

php artisan tinker --execute='
use Illuminate\Support\Facades\DB;

$definitions = [
    [
        "name" => "POV",
        "slug" => "pov",
        "description" => "POV adult videos.",
    ],
    [
        "name" => "Blonde",
        "slug" => "blonde",
        "description" => "Blonde adult videos.",
    ],
    [
        "name" => "Brunette",
        "slug" => "brunette",
        "description" => "Brunette adult videos.",
    ],
    [
        "name" => "Big Tits",
        "slug" => "big-tits",
        "description" => "Big Tits adult videos.",
    ],
    [
        "name" => "Hardcore",
        "slug" => "hardcore",
        "description" => "Hardcore adult videos.",
    ],
    [
        "name" => "Lesbian",
        "slug" => "lesbian",
        "description" => "Lesbian adult videos.",
    ],
];

DB::transaction(function () use ($definitions): void {
    foreach ($definitions as $definition) {
        $existingCategory = DB::table("categories")
            ->where("slug", $definition["slug"])
            ->first();

        if ($existingCategory) {
            DB::table("categories")
                ->where("id", $existingCategory->id)
                ->update([
                    "name" => $definition["name"],
                    "description" => $definition["description"],
                    "is_active" => true,
                    "updated_at" => now(),
                ]);

            $categoryId = $existingCategory->id;
        } else {
            $categoryId = DB::table("categories")
                ->insertGetId([
                    "name" => $definition["name"],
                    "slug" => $definition["slug"],
                    "description" => $definition["description"],
                    "is_active" => true,
                    "created_at" => now(),
                    "updated_at" => now(),
                ]);
        }

        DB::table("category_aliases")->updateOrInsert(
            [
                "source" => "*",
                "alias_type" => "tag",
                "normalized_alias" => $definition["slug"],
            ],
            [
                "category_id" => $categoryId,
                "alias" => $definition["slug"],
                "priority" => 100,
                "is_active" => true,
                "created_at" => now(),
                "updated_at" => now(),
            ]
        );
    }
});

echo "CATEGORY_WAVE1_DB_INSTALL=PASS" . PHP_EOL;
'

php artisan optimize:clear

echo "CATEGORY_WAVE1_CACHE_CLEAR=PASS"
'@

$DatabaseScript | ssh -i $KeyFile $Server 'bash -s'

Write-Host ""
Write-Host "===== FINAL WAVE-1 CHECK ====="

$FinalScript = @'
set -euo pipefail

cd /var/www/project-ares/backend

php artisan tinker --execute='
use Illuminate\Support\Facades\DB;

$slugs = [
    "pov",
    "blonde",
    "brunette",
    "big-tits",
    "hardcore",
    "lesbian",
];

echo "CATEGORIES" . PHP_EOL;

DB::table("categories")
    ->whereIn("slug", $slugs)
    ->orderBy("id")
    ->get(["id", "name", "slug", "is_active"])
    ->each(
        fn ($row) =>
            print(
                $row->id . "|" .
                $row->name . "|" .
                $row->slug . "|" .
                ($row->is_active ? "1" : "0") .
                PHP_EOL
            )
    );

echo "ALIASES" . PHP_EOL;

DB::table("category_aliases")
    ->join(
        "categories",
        "categories.id",
        "=",
        "category_aliases.category_id"
    )
    ->whereIn("categories.slug", $slugs)
    ->where("category_aliases.source", "*")
    ->where("category_aliases.alias_type", "tag")
    ->orderBy("categories.id")
    ->get([
        "categories.slug",
        "category_aliases.alias",
        "category_aliases.normalized_alias",
        "category_aliases.is_active",
    ])
    ->each(
        fn ($row) =>
            print(
                $row->slug . "|" .
                $row->alias . "|" .
                $row->normalized_alias . "|" .
                ($row->is_active ? "1" : "0") .
                PHP_EOL
            )
    );

$categoryCount = DB::table("categories")
    ->whereIn("slug", $slugs)
    ->where("is_active", true)
    ->count();

$aliasCount = DB::table("category_aliases")
    ->join(
        "categories",
        "categories.id",
        "=",
        "category_aliases.category_id"
    )
    ->whereIn("categories.slug", $slugs)
    ->where("category_aliases.source", "*")
    ->where("category_aliases.alias_type", "tag")
    ->where("category_aliases.is_active", true)
    ->count();

echo "WAVE1_CATEGORY_COUNT=" . $categoryCount . PHP_EOL;
echo "WAVE1_ALIAS_COUNT=" . $aliasCount . PHP_EOL;

if ($categoryCount !== 6 || $aliasCount < 6) {
    throw new RuntimeException(
        "Wave-1 category/alias verification failed."
    );
}

echo "CATEGORY_WAVE1_FINAL=PASS" . PHP_EOL;
'

echo "CATEGORY_WAVE1_INSTALL=PASS"
'@

$FinalScript | ssh -i $KeyFile $Server 'bash -s'
