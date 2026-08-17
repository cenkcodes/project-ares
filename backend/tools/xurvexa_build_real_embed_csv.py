from __future__ import annotations

import csv
import re
from pathlib import Path

try:
    import yt_dlp
except ImportError:
    print()
    print("yt-dlp is not installed.")
    print("Run:")
    print('  python -m pip install -U "yt-dlp[default]"')
    print()
    raise SystemExit(1)


PROJECT_ROOT = Path(__file__).resolve().parents[1]

OUTPUT_FILE = (
    PROJECT_ROOT
    / "storage"
    / "app"
    / "import-tests"
    / "xurvexa_real_embed_test.csv"
)

CATEGORY_SLUG = "test-category"

SOURCE_URLS = [
    "https://www.xvideos.com/video65982001/what_s_her_name",
    "https://www.xvideos.com/video4588838/biker_takes_his_girl",
]


def slugify(value: str) -> str:
    slug = value.lower()
    slug = re.sub(r"[^a-z0-9]+", "-", slug)
    slug = slug.strip("-")

    return slug or "video"


def clean_text(value: object) -> str:
    if value is None:
        return ""

    return (
        str(value)
        .replace("\r", " ")
        .replace("\n", " ")
        .strip()
    )


def get_height(info: dict) -> int | None:
    height = info.get("height")

    if isinstance(height, (int, float)):
        return int(height)

    formats = info.get("formats")

    if not isinstance(formats, list):
        return None

    heights = [
        int(item["height"])
        for item in formats
        if isinstance(item, dict)
        and isinstance(item.get("height"), (int, float))
    ]

    if not heights:
        return None

    return max(heights)


ydl_options = {
    "quiet": True,
    "no_warnings": True,
    "skip_download": True,
    "noplaylist": True,
}

fieldnames = [
    "title",
    "slug",
    "description",
    "embed_url",
    "video_source",
    "thumbnail",
    "duration",
    "category",
    "views",
    "is_hd",
    "is_4k",
    "is_featured",
    "is_premium",
    "is_active",
]

rows: list[dict[str, object]] = []

with yt_dlp.YoutubeDL(ydl_options) as ydl:
    for source_url in SOURCE_URLS:
        print(f"Fetching metadata: {source_url}")

        info = ydl.extract_info(
            source_url,
            download=False,
        )

        if not info:
            raise RuntimeError(
                f"No metadata returned for {source_url}"
            )

        video_id = clean_text(info.get("id"))
        title = clean_text(info.get("title"))
        description = clean_text(
            info.get("description")
        )
        thumbnail = clean_text(
            info.get("thumbnail")
        )
        duration = info.get("duration")
        height = get_height(info)

        if not video_id:
            raise RuntimeError(
                "Video ID could not be extracted: "
                f"{source_url}"
            )

        if not title:
            raise RuntimeError(
                "Title could not be extracted: "
                f"{source_url}"
            )

        if not thumbnail:
            raise RuntimeError(
                "Thumbnail could not be extracted: "
                f"{source_url}"
            )

        if duration is None:
            raise RuntimeError(
                "Duration could not be extracted: "
                f"{source_url}"
            )

        embed_url = (
            "https://www.xvideos.com/"
            f"embedframe/{video_id}"
        )

        is_hd: object = ""
        is_4k: object = ""

        if height is not None:
            is_hd = int(height >= 720)
            is_4k = int(height >= 2160)

        rows.append(
            {
                "title": title,
                "slug": slugify(title),
                "description": description,
                "embed_url": embed_url,
                "video_source": "xvideos",
                "thumbnail": thumbnail,
                "duration": int(duration),
                "category": CATEGORY_SLUG,
                "views": 0,
                "is_hd": is_hd,
                "is_4k": is_4k,
                "is_featured": 0,
                "is_premium": 0,
                "is_active": 1,
            }
        )


OUTPUT_FILE.parent.mkdir(
    parents=True,
    exist_ok=True,
)

with OUTPUT_FILE.open(
    "w",
    newline="",
    encoding="utf-8-sig",
) as handle:
    writer = csv.DictWriter(
        handle,
        fieldnames=fieldnames,
    )

    writer.writeheader()
    writer.writerows(rows)


print()
print("CSV CREATED:")
print(OUTPUT_FILE)
print()

for row in rows:
    print("-" * 72)

    for key in fieldnames:
        print(f"{key}: {row[key]}")
