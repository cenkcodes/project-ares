#!/usr/bin/env python3
"""
Xurvexa Eporner canonical CSV builder.

Reads Eporner video IDs collected by eporner-api-collector.py, fetches each
video through the official Eporner API v2 id endpoint, applies Xurvexa's
shared global content policy, writes the canonical 14-column VideoImporter
CSV, and writes a source-term sidecar CSV from Eporner keywords.
"""

from __future__ import annotations

import argparse
import csv
import html
import json
import re
import sys
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from pathlib import Path

from video_import_policy import (
    POLICY_VERSION,
    blocked_term_count,
    matched_blocked_term,
    validate_policy,
)

API_ID = "https://www.eporner.com/api/v2/video/id/"
VIDEO_SOURCE = "eporner"
USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/151.0.0.0 Safari/537.36"
)
VIDEO_ID_RE = re.compile(r"^[A-Za-z0-9_-]+$")

CANONICAL_HEADERS = [
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
REJECT_HEADERS = ["video_id", "reason"]
SOURCE_TERM_HEADERS = [
    "video_slug",
    "video_source",
    "term_type",
    "term",
    "normalized_term",
]


class BuilderError(RuntimeError):
    pass


@dataclass(frozen=True)
class VideoMetadata:
    video_id: str
    title: str
    description: str
    embed_url: str
    thumbnail: str
    duration: int
    views: int
    tags: tuple[str, ...]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Build Xurvexa canonical CSV from Eporner API video IDs."
    )
    parser.add_argument("--input", required=True)
    parser.add_argument(
        "--category",
        required=True,
        help="Existing Xurvexa canonical category slug, e.g. milf.",
    )
    parser.add_argument("--output")
    parser.add_argument("--rejects")
    parser.add_argument("--source-terms")
    parser.add_argument(
        "--max-accepted",
        type=int,
        default=0,
        help="0 means no limit.",
    )
    parser.add_argument("--timeout", type=int, default=30)
    parser.add_argument("--retries", type=int, default=3)
    parser.add_argument("--delay", type=float, default=0.30)
    return parser.parse_args()


def normalize_whitespace(value: str) -> str:
    return re.sub(r"\s+", " ", value).strip()


def normalize_source_term(value: str) -> str:
    value = html.unescape(value)
    value = unicodedata.normalize("NFKD", value)
    value = value.encode("ascii", "ignore").decode("ascii")
    value = value.lower()
    value = re.sub(r"[^a-z0-9]+", "-", value)
    value = re.sub(r"-+", "-", value)
    return value.strip("-")


def source_term_is_ascii_safe(value: str) -> bool:
    value = html.unescape(value)
    value = unicodedata.normalize("NFKD", value)
    return not any(
        ord(character) > 127
        and unicodedata.category(character)[0] in {"L", "N"}
        for character in value
    )


def validate_category_slug(category: str) -> str:
    category = category.strip().lower()
    if not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", category):
        raise BuilderError(
            "Category must be an existing lowercase canonical slug."
        )
    return category


def validate_args(args: argparse.Namespace) -> None:
    if args.timeout <= 0:
        raise BuilderError("--timeout must be greater than zero.")
    if args.retries <= 0:
        raise BuilderError("--retries must be greater than zero.")
    if args.delay < 0:
        raise BuilderError("--delay cannot be negative.")
    if args.max_accepted < 0:
        raise BuilderError("--max-accepted cannot be negative.")


def validate_video_id(value: str) -> str:
    value = value.strip()
    if not VIDEO_ID_RE.fullmatch(value):
        raise BuilderError(f"Invalid Eporner video ID: {value}")
    return value


def read_ids(path: Path) -> list[str]:
    if not path.is_file():
        raise BuilderError(f"Input file not found: {path}")

    ids: list[str] = []
    seen: set[str] = set()

    for raw_line in path.read_text(encoding="utf-8-sig").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue
        video_id = validate_video_id(line)
        key = video_id.lower()
        if key not in seen:
            ids.append(video_id)
            seen.add(key)

    if not ids:
        raise BuilderError("Input file contains no usable Eporner IDs.")

    return ids


def fetch_json(url: str, timeout: int, retries: int) -> dict:
    last_error: Exception | None = None

    for attempt in range(1, retries + 1):
        request = urllib.request.Request(
            url,
            headers={
                "User-Agent": USER_AGENT,
                "Accept": "application/json",
                "Accept-Language": "en-US,en;q=0.9",
                "Cache-Control": "no-cache",
                "Pragma": "no-cache",
            },
        )

        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                status = getattr(response, "status", 200)
                if status != 200:
                    raise BuilderError(
                        f"Unexpected HTTP status {status} for {url}"
                    )
                payload = response.read()
                if not payload:
                    raise BuilderError("Downloaded API response is empty.")
                decoded = json.loads(payload.decode("utf-8"))
                if not isinstance(decoded, dict):
                    raise BuilderError("API response is not a JSON object.")
                return decoded
        except (
            urllib.error.URLError,
            urllib.error.HTTPError,
            TimeoutError,
            json.JSONDecodeError,
            BuilderError,
        ) as exc:
            last_error = exc
            if attempt < retries:
                time.sleep(min(2.0 * attempt, 5.0))

    raise BuilderError(
        f"API fetch failed after {retries} attempt(s): {last_error}"
    )


def detail_url(video_id: str) -> str:
    params = urllib.parse.urlencode(
        {
            "id": video_id,
            "thumbsize": "big",
            "format": "json",
        }
    )
    return API_ID + "?" + params


def parse_int(value: object, default: int = 0) -> int:
    if isinstance(value, bool):
        return default
    if isinstance(value, int):
        return max(0, value)
    if isinstance(value, float):
        return max(0, int(value))
    if isinstance(value, str):
        cleaned = re.sub(r"[^0-9]", "", value)
        if cleaned:
            return int(cleaned)
    return default


def extract_tags(value: object) -> tuple[str, ...]:
    candidates: list[str] = []

    if isinstance(value, str):
        candidates.extend(value.split(","))
    elif isinstance(value, list):
        candidates.extend(str(item) for item in value if item is not None)

    deduped: list[str] = []
    seen: set[str] = set()

    for item in candidates:
        cleaned = normalize_whitespace(html.unescape(str(item)))
        if not cleaned:
            continue
        normalized = normalize_source_term(cleaned)
        if not normalized or normalized in seen:
            continue
        seen.add(normalized)
        deduped.append(cleaned)

    return tuple(deduped)


def extract_thumbnail(detail: dict) -> str:
    default_thumb = detail.get("default_thumb")
    if isinstance(default_thumb, dict):
        src = default_thumb.get("src")
        if isinstance(src, str) and src.startswith(("https://", "http://")):
            return src

    thumbs = detail.get("thumbs")
    if isinstance(thumbs, list):
        for item in thumbs:
            if isinstance(item, dict):
                src = item.get("src")
                if isinstance(src, str) and src.startswith(("https://", "http://")):
                    return src
            elif isinstance(item, str) and item.startswith(("https://", "http://")):
                return item

    raise BuilderError("Thumbnail metadata is missing or invalid.")


def validate_embed_url(value: object, video_id: str) -> str:
    embed = str(value or "").strip()
    if not embed:
        embed = f"https://www.eporner.com/embed/{video_id}/"

    parsed = urllib.parse.urlparse(embed)
    host = (parsed.hostname or "").lower()
    if parsed.scheme != "https" or host not in {"eporner.com", "www.eporner.com"}:
        raise BuilderError("Embed URL is not an HTTPS Eporner URL.")

    match = re.search(r"/embed/([A-Za-z0-9_-]+)/?", parsed.path)
    if not match:
        raise BuilderError("Embed URL does not contain an Eporner video ID.")
    if match.group(1).lower() != video_id.lower():
        raise BuilderError("Embed URL video ID does not match API video ID.")

    return urllib.parse.urlunparse(
        ("https", "www.eporner.com", parsed.path, "", "", "")
    )


def build_metadata(video_id: str, timeout: int, retries: int) -> VideoMetadata:
    detail = fetch_json(detail_url(video_id), timeout, retries)

    api_id = detail.get("id")
    if not isinstance(api_id, str) or api_id.lower() != video_id.lower():
        raise BuilderError("API detail ID does not match requested video ID.")

    title = normalize_whitespace(html.unescape(str(detail.get("title") or "")))
    if not title:
        raise BuilderError("Title metadata is missing.")

    duration = parse_int(detail.get("length_sec"))
    if duration <= 0:
        raise BuilderError("Duration metadata is missing or invalid.")

    tags = extract_tags(detail.get("keywords"))
    safe_tags = tuple(tag for tag in tags if source_term_is_ascii_safe(tag))
    if not safe_tags:
        raise BuilderError("No usable ASCII-safe source tags were provided.")

    return VideoMetadata(
        video_id=api_id,
        title=title,
        description="",
        embed_url=validate_embed_url(detail.get("embed"), api_id),
        thumbnail=extract_thumbnail(detail),
        duration=duration,
        views=parse_int(detail.get("views")),
        tags=safe_tags,
    )


def metadata_policy_reason(metadata: VideoMetadata) -> str | None:
    for field_name, value in (
        ("title", metadata.title),
        ("description", metadata.description),
    ):
        term = matched_blocked_term(value)
        if term:
            return f"Blocked by global policy term '{term}' in {field_name}."

    for tag in metadata.tags:
        term = matched_blocked_term(tag)
        if term:
            return (
                f"Blocked by global policy term '{term}' "
                f"in source tag '{tag}'."
            )

    return None


def build_slug(metadata: VideoMetadata) -> str:
    return f"{VIDEO_SOURCE}-{metadata.video_id.lower()}"


def metadata_to_row(
    metadata: VideoMetadata,
    category: str,
) -> dict[str, object]:
    return {
        "title": metadata.title,
        "slug": build_slug(metadata),
        "description": metadata.description,
        "embed_url": metadata.embed_url,
        "video_source": VIDEO_SOURCE,
        "thumbnail": metadata.thumbnail,
        "duration": metadata.duration,
        "category": category,
        # Xurvexa views are site-local engagement, not provider view totals.
        "views": 0,
        "is_hd": 0,
        "is_4k": 0,
        "is_featured": 0,
        "is_premium": 0,
        "is_active": 1,
    }


def metadata_to_source_terms(
    metadata: VideoMetadata,
) -> list[dict[str, str]]:
    video_slug = build_slug(metadata)
    rows: list[dict[str, str]] = []

    for tag in metadata.tags:
        normalized = normalize_source_term(tag)
        if not normalized:
            continue
        rows.append(
            {
                "video_slug": video_slug,
                "video_source": VIDEO_SOURCE,
                "term_type": "tag",
                "term": tag,
                "normalized_term": normalized,
            }
        )

    return rows


def write_csv(
    path: Path,
    fieldnames: list[str],
    rows: list[dict[str, object]],
) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=fieldnames,
            extrasaction="raise",
        )
        writer.writeheader()
        writer.writerows(rows)


def default_paths(
    input_path: Path,
    category: str,
    output_arg: str | None,
    rejects_arg: str | None,
    source_terms_arg: str | None,
) -> tuple[Path, Path, Path]:
    timestamp = time.strftime("%Y%m%d-%H%M%S")

    output_path = (
        Path(output_arg).expanduser()
        if output_arg
        else input_path.parent / f"xurvexa-eporner-{category}-{timestamp}.csv"
    )
    rejects_path = (
        Path(rejects_arg).expanduser()
        if rejects_arg
        else output_path.with_name(output_path.stem + "-rejected.csv")
    )
    source_terms_path = (
        Path(source_terms_arg).expanduser()
        if source_terms_arg
        else output_path.with_name(output_path.stem + "-source-terms.csv")
    )
    return output_path, rejects_path, source_terms_path


def main() -> int:
    args = parse_args()

    try:
        validate_policy()
        validate_args(args)
        category = validate_category_slug(args.category)
        input_path = Path(args.input).expanduser()
        video_ids = read_ids(input_path)

        output_path, rejects_path, source_terms_path = default_paths(
            input_path,
            category,
            args.output,
            args.rejects,
            args.source_terms,
        )

        rows: list[dict[str, object]] = []
        rejects: list[dict[str, object]] = []
        source_term_rows: list[dict[str, object]] = []
        seen_ids: set[str] = set()

        print(f"INPUT_IDS={len(video_ids)}")
        print(f"CATEGORY={category}")
        print(f"VIDEO_SOURCE={VIDEO_SOURCE}")
        print(f"MAX_ACCEPTED={args.max_accepted}")
        print(f"POLICY_VERSION={POLICY_VERSION}")
        print(f"POLICY_TERMS={blocked_term_count()}")

        for index, raw_id in enumerate(video_ids, start=1):
            if args.max_accepted > 0 and len(rows) >= args.max_accepted:
                print("STOP_REASON=MAX_ACCEPTED_REACHED")
                break

            video_id = ""
            try:
                video_id = validate_video_id(raw_id)
                key = video_id.lower()
                if key in seen_ids:
                    raise BuilderError("Duplicate video ID in input.")

                metadata = build_metadata(
                    video_id,
                    timeout=args.timeout,
                    retries=max(1, args.retries),
                )

                policy_reason = metadata_policy_reason(metadata)
                if policy_reason:
                    raise BuilderError(policy_reason)

                term_rows = metadata_to_source_terms(metadata)
                if not term_rows:
                    raise BuilderError("No usable source-term rows were produced.")

                rows.append(metadata_to_row(metadata, category))
                source_term_rows.extend(term_rows)
                seen_ids.add(key)

                print(
                    f"[{index}/{len(video_ids)}] OK "
                    f"id={metadata.video_id} "
                    f"duration={metadata.duration} "
                    f"tags={len(metadata.tags)} "
                    f"title={metadata.title}"
                )
            except Exception as exc:
                reason = normalize_whitespace(str(exc))
                rejects.append(
                    {
                        "video_id": video_id or raw_id,
                        "reason": reason,
                    }
                )
                print(
                    f"[{index}/{len(video_ids)}] REJECT "
                    f"id={video_id or raw_id or '-'} "
                    f"reason={reason}",
                    file=sys.stderr,
                )

            if (
                index < len(video_ids)
                and args.delay > 0
                and not (
                    args.max_accepted > 0
                    and len(rows) >= args.max_accepted
                )
            ):
                time.sleep(args.delay)

        if not rows:
            write_csv(rejects_path, REJECT_HEADERS, rejects)
            raise BuilderError("No importable rows were produced.")

        write_csv(output_path, CANONICAL_HEADERS, rows)
        write_csv(source_terms_path, SOURCE_TERM_HEADERS, source_term_rows)
        write_csv(rejects_path, REJECT_HEADERS, rejects)

        print()
        print(f"CSV_CREATED={output_path.resolve()}")
        print(f"SOURCE_TERMS_FILE={source_terms_path.resolve()}")
        print(f"ROWS_ACCEPTED={len(rows)}")
        print(f"ROWS_REJECTED={len(rejects)}")
        print(f"SOURCE_TERMS_COUNT={len(source_term_rows)}")
        print(f"COLUMN_COUNT={len(CANONICAL_HEADERS)}")
        print("HEADERS=" + ",".join(CANONICAL_HEADERS))
        print("SOURCE_TERM_HEADERS=" + ",".join(SOURCE_TERM_HEADERS))
        print(f"REJECTS_FILE={rejects_path.resolve()}")
        print("BUILD_STATUS=PASS")
        return 0

    except BuilderError as exc:
        print(f"BUILD_STATUS=FAIL: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
