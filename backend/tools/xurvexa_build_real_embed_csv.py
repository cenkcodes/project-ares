from __future__ import annotations

import argparse
import csv
import re
from pathlib import Path
from urllib.parse import urlparse

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

DEFAULT_OUTPUT_FILE = (
    PROJECT_ROOT
    / "storage"
    / "app"
    / "import-tests"
    / "xurvexa_real_embed_test.csv"
)

MAX_TITLE_LENGTH = 1000
MAX_SLUG_LENGTH = 255
MAX_DESCRIPTION_LENGTH = 100000
MAX_URL_LENGTH = 8192
MAX_VIDEO_SOURCE_LENGTH = 255
MAX_DURATION_SECONDS = 31536000

FIELDNAMES = [
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


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Build a Filament-compatible Xurvexa video import CSV "
            "from real Xvideos URLs."
        )
    )

    parser.add_argument(
        "--category",
        required=True,
        help=(
            "Existing Xurvexa category slug. "
            "Example: test-category"
        ),
    )

    parser.add_argument(
        "--url",
        action="append",
        required=True,
        dest="source_urls",
        help=(
            "Source Xvideos video URL. "
            "Use --url multiple times for multiple videos."
        ),
    )

    parser.add_argument(
        "--output",
        type=Path,
        default=DEFAULT_OUTPUT_FILE,
        help=(
            "Output CSV path. "
            "Defaults to storage/app/import-tests/"
            "xurvexa_real_embed_test.csv"
        ),
    )

    return parser.parse_args()


def clean_text(value: object) -> str:
    if value is None:
        return ""

    return (
        str(value)
        .replace("\r", " ")
        .replace("\n", " ")
        .strip()
    )


def validate_length(
    field_name: str,
    value: str,
    maximum: int,
    source_url: str,
) -> None:
    if len(value) > maximum:
        raise RuntimeError(
            f"{field_name} exceeds the allowed limit "
            f"of {maximum} characters for: {source_url}"
        )


def validate_http_url(
    field_name: str,
    value: str,
    source_url: str,
) -> None:
    parsed = urlparse(value)

    if (
        parsed.scheme not in {"http", "https"}
        or not parsed.netloc
    ):
        raise RuntimeError(
            f"{field_name} is not a valid HTTP/HTTPS URL "
            f"for: {source_url}"
        )


def validate_source_url(source_url: str) -> None:
    validate_http_url(
        "Source URL",
        source_url,
        source_url,
    )

    hostname = (
        urlparse(source_url)
        .hostname
        or ""
    ).lower()

    if not (
        hostname == "xvideos.com"
        or hostname.endswith(".xvideos.com")
    ):
        raise RuntimeError(
            "This helper currently supports only "
            f"Xvideos URLs: {source_url}"
        )


def validate_category_slug(
    category_slug: str,
) -> str:
    category_slug = category_slug.strip()

    if not category_slug:
        raise RuntimeError(
            "Category slug cannot be empty."
        )

    if len(category_slug) > MAX_SLUG_LENGTH:
        raise RuntimeError(
            "Category slug exceeds the allowed limit "
            f"of {MAX_SLUG_LENGTH} characters."
        )

    return category_slug


def slugify(
    title: str,
    video_id: str,
) -> str:
    base_slug = title.lower()
    base_slug = re.sub(
        r"[^a-z0-9]+",
        "-",
        base_slug,
    )
    base_slug = base_slug.strip("-")

    if not base_slug:
        base_slug = "video"

    safe_video_id = re.sub(
        r"[^a-zA-Z0-9_-]+",
        "-",
        video_id,
    ).strip("-")

    if not safe_video_id:
        safe_video_id = "source"

    suffix = f"-{safe_video_id}"

    maximum_base_length = (
        MAX_SLUG_LENGTH
        - len(suffix)
    )

    if maximum_base_length < 1:
        raise RuntimeError(
            "Video ID is too long to build "
            "a valid unique slug."
        )

    base_slug = (
        base_slug[:maximum_base_length]
        .rstrip("-")
    )

    if not base_slug:
        base_slug = "video"

    slug = f"{base_slug}{suffix}"

    if len(slug) > MAX_SLUG_LENGTH:
        raise RuntimeError(
            "Generated slug exceeds the allowed "
            f"limit of {MAX_SLUG_LENGTH} characters."
        )

    return slug


def get_height(
    info: dict,
) -> int | None:
    height = info.get("height")

    if isinstance(
        height,
        (int, float),
    ):
        return int(height)

    formats = info.get("formats")

    if not isinstance(
        formats,
        list,
    ):
        return None

    heights = [
        int(item["height"])
        for item in formats
        if isinstance(item, dict)
        and isinstance(
            item.get("height"),
            (int, float),
        )
    ]

    if not heights:
        return None

    return max(heights)


def normalize_duration(
    value: object,
    source_url: str,
) -> int:
    if not isinstance(
        value,
        (int, float),
    ):
        raise RuntimeError(
            "Duration could not be extracted "
            f"for: {source_url}"
        )

    duration = int(value)

    if (
        duration < 0
        or duration > MAX_DURATION_SECONDS
    ):
        raise RuntimeError(
            "Duration is outside the allowed "
            f"range for: {source_url}"
        )

    return duration


def build_row(
    info: dict,
    source_url: str,
    category_slug: str,
) -> dict[str, object]:
    video_id = clean_text(
        info.get("id")
    )

    title = clean_text(
        info.get("title")
    )

    description = clean_text(
        info.get("description")
    )

    thumbnail = clean_text(
        info.get("thumbnail")
    )

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

    validate_length(
        "Title",
        title,
        MAX_TITLE_LENGTH,
        source_url,
    )

    validate_length(
        "Description",
        description,
        MAX_DESCRIPTION_LENGTH,
        source_url,
    )

    validate_http_url(
        "Thumbnail",
        thumbnail,
        source_url,
    )

    validate_length(
        "Thumbnail",
        thumbnail,
        MAX_URL_LENGTH,
        source_url,
    )

    duration = normalize_duration(
        info.get("duration"),
        source_url,
    )

    embed_url = (
        "https://www.xvideos.com/"
        f"embedframe/{video_id}"
    )

    validate_http_url(
        "Embed URL",
        embed_url,
        source_url,
    )

    validate_length(
        "Embed URL",
        embed_url,
        MAX_URL_LENGTH,
        source_url,
    )

    video_source = "xvideos"

    validate_length(
        "Video Source",
        video_source,
        MAX_VIDEO_SOURCE_LENGTH,
        source_url,
    )

    height = get_height(info)

    is_hd: object = ""
    is_4k: object = ""

    if height is not None:
        is_hd = int(
            height >= 720
        )
        is_4k = int(
            height >= 2160
        )

    return {
        "title": title,
        "slug": slugify(
            title,
            video_id,
        ),
        "description": description,
        "embed_url": embed_url,
        "video_source": video_source,
        "thumbnail": thumbnail,
        "duration": duration,
        "category": category_slug,
        "views": 0,
        "is_hd": is_hd,
        "is_4k": is_4k,
        "is_featured": 0,
        "is_premium": 0,
        "is_active": 1,
    }


def main() -> None:
    args = parse_arguments()

    category_slug = validate_category_slug(
        args.category
    )

    source_urls = [
        url.strip()
        for url in args.source_urls
        if url.strip()
    ]

    if not source_urls:
        raise RuntimeError(
            "At least one source URL is required."
        )

    for source_url in source_urls:
        validate_source_url(
            source_url
        )

    output_file = args.output

    if not output_file.is_absolute():
        output_file = (
            PROJECT_ROOT
            / output_file
        ).resolve()

    ydl_options = {
        "quiet": True,
        "no_warnings": True,
        "skip_download": True,
        "noplaylist": True,
    }

    rows: list[
        dict[str, object]
    ] = []

    with yt_dlp.YoutubeDL(
        ydl_options
    ) as ydl:
        for source_url in source_urls:
            print(
                f"Fetching metadata: {source_url}"
            )

            info = ydl.extract_info(
                source_url,
                download=False,
            )

            if not isinstance(
                info,
                dict,
            ):
                raise RuntimeError(
                    "No usable metadata returned "
                    f"for: {source_url}"
                )

            rows.append(
                build_row(
                    info,
                    source_url,
                    category_slug,
                )
            )

    output_file.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    with output_file.open(
        "w",
        newline="",
        encoding="utf-8-sig",
    ) as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=FIELDNAMES,
        )

        writer.writeheader()
        writer.writerows(rows)

    print()
    print("CSV CREATED:")
    print(output_file)
    print()

    print(
        f"Category: {category_slug}"
    )

    print(
        f"Rows: {len(rows)}"
    )

    print()

    for row in rows:
        print("-" * 72)

        for key in FIELDNAMES:
            print(
                f"{key}: {row[key]}"
            )


if __name__ == "__main__":
    main()
