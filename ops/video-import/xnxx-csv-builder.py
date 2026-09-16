#!/usr/bin/env python3
"""
Xurvexa XNXX canonical CSV builder.

Reads public XNXX watch-page URLs, extracts public metadata, applies Xurvexa's
global exclusion policy, writes the canonical 14-column VideoImporter CSV,
and writes a source-term sidecar CSV containing the source site's ready-made
tags for later insertion into video_source_terms.

Canonical columns:
title,slug,description,embed_url,video_source,thumbnail,duration,category,
views,is_hd,is_4k,is_featured,is_premium,is_active

Source-term sidecar columns:
video_slug,video_source,term_type,term,normalized_term
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

from video_title_quality import prepare_source_title

from video_import_policy import (
    POLICY_VERSION,
    blocked_term_count,
    matched_blocked_term,
    validate_policy,
)

CANONICAL_HEADERS = [
    "title", "slug", "description", "embed_url", "video_source",
    "thumbnail", "duration", "category", "views", "is_hd", "is_4k",
    "is_featured", "is_premium", "is_active",
]

REJECT_HEADERS = ["url", "video_id", "reason"]

SOURCE_TERM_HEADERS = [
    "video_slug",
    "video_source",
    "term_type",
    "term",
    "normalized_term",
]

VIDEO_SOURCE = "xnxx"

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/151.0.0.0 Safari/537.36"
)

VIDEO_ID_PATTERNS = [
    re.compile(r"/video-([a-z0-9_-]+)/", re.IGNORECASE),
    re.compile(r"/embedframe/([a-z0-9_-]+)", re.IGNORECASE),
]



class BuilderError(RuntimeError):
    pass


@dataclass(frozen=True)
class VideoMetadata:
    source_url: str
    video_id: str
    title: str
    description: str
    thumbnail: str
    duration: int
    tags: tuple[str, ...]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Build Xurvexa canonical CSV from XNXX URLs."
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
        help="0 means no limit; use 500 for a 500-video category batch.",
    )
    parser.add_argument("--timeout", type=int, default=30)
    parser.add_argument("--retries", type=int, default=3)
    parser.add_argument("--delay", type=float, default=0.75)
    return parser.parse_args()


def normalize_whitespace(value: str) -> str:
    return re.sub(r"\s+", " ", value).strip()




SOURCE_TERM_CANONICAL_PUNCTUATION_TRANSLATION = str.maketrans(
    {
        "\u2018": "'",
        "\u2019": "'",
        "\u201a": "'",
        "\u201b": "'",
        "\u2013": "-",
        "\u2014": "-",
        "\uff07": None,
        "\ufe63": None,
        "\uff0d": None,
    }
)


def normalize_source_term(value: str) -> str:
    value = html.unescape(value)
    value = value.translate(SOURCE_TERM_CANONICAL_PUNCTUATION_TRANSLATION)
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
            "Category must be an existing lowercase canonical slug "
            "such as 'milf' or 'asian'."
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


def read_urls(path: Path) -> list[str]:
    if not path.is_file():
        raise BuilderError(f"Input file not found: {path}")

    urls: list[str] = []
    seen: set[str] = set()

    for raw_line in path.read_text(encoding="utf-8-sig").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue
        if line not in seen:
            urls.append(line)
            seen.add(line)

    if not urls:
        raise BuilderError("Input file contains no usable URLs.")

    return urls


def validate_xnxx_url(url: str) -> str:
    parsed = urllib.parse.urlparse(url.strip())
    if parsed.scheme not in {"http", "https"}:
        raise BuilderError("URL must use http or https.")

    host = (parsed.hostname or "").lower()
    if host not in {"xnxx.com", "www.xnxx.com"}:
        raise BuilderError(
            f"Unsupported host '{host}'. Expected www.xnxx.com."
        )

    return urllib.parse.urlunparse(
        ("https", "www.xnxx.com", parsed.path, "", parsed.query, "")
    )


def extract_video_id(url: str) -> str:
    for pattern in VIDEO_ID_PATTERNS:
        match = pattern.search(url)
        if match:
            return match.group(1).lower()
    raise BuilderError("Video ID could not be extracted from URL.")


def fetch_html(url: str, timeout: int, retries: int) -> str:
    last_error: Exception | None = None

    for attempt in range(1, retries + 1):
        request = urllib.request.Request(
            url,
            headers={
                "User-Agent": USER_AGENT,
                "Accept": (
                    "text/html,application/xhtml+xml,"
                    "application/xml;q=0.9,*/*;q=0.8"
                ),
                "Accept-Language": "en-US,en;q=0.9",
                "Cache-Control": "no-cache",
                "Pragma": "no-cache",
            },
        )

        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                status = getattr(response, "status", 200)
                if status != 200:
                    raise BuilderError(f"Unexpected HTTP status: {status}")
                payload = response.read()
                if not payload:
                    raise BuilderError("Downloaded page is empty.")
                return payload.decode("utf-8", errors="replace")
        except (
            urllib.error.URLError,
            urllib.error.HTTPError,
            TimeoutError,
            BuilderError,
        ) as exc:
            last_error = exc
            if attempt < retries:
                time.sleep(min(2.0 * attempt, 5.0))

    raise BuilderError(
        f"Page download failed after {retries} attempt(s): {last_error}"
    )


def resolve_metadata_page(
    input_url: str,
    timeout: int,
    retries: int,
) -> tuple[str, str]:
    normalized = validate_xnxx_url(input_url)
    parsed = urllib.parse.urlparse(normalized)

    if parsed.path.lower().startswith("/embedframe/"):
        raise BuilderError(
            "XNXX embed URLs are not accepted as metadata inputs. "
            "Use the public watch-page URL collected by xnxx-url-collector.py."
        )

    if not parsed.path.lower().startswith("/video-"):
        raise BuilderError("XNXX watch URL must start with /video-.")

    return normalized, fetch_html(normalized, timeout, retries)

def extract_meta(document: str, name: str, *, raw: bool = False) -> str:
    escaped = re.escape(name)
    patterns = [
        rf'<meta[^>]+property=["\']{escaped}["\'][^>]+content=["\']([^"\']*)["\']',
        rf'<meta[^>]+content=["\']([^"\']*)["\'][^>]+property=["\']{escaped}["\']',
        rf'<meta[^>]+name=["\']{escaped}["\'][^>]+content=["\']([^"\']*)["\']',
        rf'<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']{escaped}["\']',
    ]

    for pattern in patterns:
        match = re.search(pattern, document, flags=re.IGNORECASE)
        if match:
            if raw:
                return match.group(1)
            return normalize_whitespace(
                html.unescape(match.group(1))
            )

    return ""


def parse_iso8601_duration(value: str) -> int | None:
    match = re.fullmatch(
        r"P"
        r"(?:(?P<days>\d+)D)?"
        r"(?:T"
        r"(?:(?P<hours>\d+)H)?"
        r"(?:(?P<minutes>\d+)M)?"
        r"(?:(?P<seconds>\d+)S)?"
        r")?",
        value.strip(),
        flags=re.IGNORECASE,
    )
    if not match:
        return None

    total = (
        int(match.group("days") or 0) * 86400
        + int(match.group("hours") or 0) * 3600
        + int(match.group("minutes") or 0) * 60
        + int(match.group("seconds") or 0)
    )
    return total if total > 0 else None


def extract_duration(document: str) -> int:
    og_duration = extract_meta(document, "og:duration")
    if og_duration.isdigit() and int(og_duration) > 0:
        return int(og_duration)

    iso_match = re.search(
        r'["\']duration["\']\s*:\s*["\'](P[^"\']+)["\']',
        document,
        flags=re.IGNORECASE,
    )
    if iso_match:
        parsed = parse_iso8601_duration(iso_match.group(1))
        if parsed:
            return parsed

    raise BuilderError("Duration metadata could not be extracted.")


def extract_title(document: str) -> str:
    title = extract_meta(document, "og:title", raw=True)
    if title:
        return prepare_source_title(title)

    match = re.search(
        r"html5player\.setVideoTitle\(\s*['\"]([^'\"]*)['\"]\s*\)",
        document,
        flags=re.IGNORECASE,
    )
    if match:
        return prepare_source_title(match.group(1))

    raise BuilderError("Title metadata could not be extracted.")


def extract_thumbnail(document: str) -> str:
    thumbnail = extract_meta(document, "og:image")
    if not thumbnail:
        patterns = [
            r"html5player\.setThumbUrl169\(\s*['\"]([^'\"]+)['\"]\s*\)",
            r"html5player\.setThumbUrl\(\s*['\"]([^'\"]+)['\"]\s*\)",
        ]
        for pattern in patterns:
            match = re.search(pattern, document, flags=re.IGNORECASE)
            if match:
                thumbnail = html.unescape(match.group(1)).strip()
                break

    if not thumbnail.startswith(("https://", "http://")):
        raise BuilderError("Thumbnail metadata is missing or invalid.")
    return thumbnail


def extract_tags(document: str) -> tuple[str, ...]:
    candidates: list[str] = []

    for match in re.finditer(
        r'"video_tags"\s*:\s*(\[[^\]]*\])',
        document,
        flags=re.IGNORECASE,
    ):
        try:
            decoded = json.loads(match.group(1))
        except json.JSONDecodeError:
            continue

        if isinstance(decoded, list):
            for item in decoded:
                if isinstance(item, str):
                    cleaned = normalize_whitespace(html.unescape(item))
                    if cleaned:
                        candidates.append(cleaned)
            if candidates:
                break

    keywords = extract_meta(document, "keywords")
    if keywords:
        for item in keywords.split(","):
            cleaned = normalize_whitespace(html.unescape(item))
            if cleaned:
                candidates.append(cleaned)

    deduped: list[str] = []
    seen: set[str] = set()

    for tag in candidates:
        normalized = normalize_source_term(tag)
        if not normalized:
            continue
        if normalized not in seen:
            seen.add(normalized)
            deduped.append(tag)

    return tuple(deduped)


def metadata_policy_reason(metadata: VideoMetadata) -> str | None:
    for field_name, value in (
        ("title", metadata.title),
        ("description", metadata.description),
    ):
        term = matched_blocked_term(value)
        if term:
            return (
                f"Blocked by global policy term '{term}' "
                f"in {field_name}."
            )

    for tag in metadata.tags:
        term = matched_blocked_term(tag)
        if term:
            return (
                f"Blocked by global policy term '{term}' "
                f"in source tag '{tag}'."
            )

    return None


def build_metadata(
    url: str,
    timeout: int,
    retries: int,
) -> VideoMetadata:
    normalized_input = validate_xnxx_url(url)
    input_video_id = extract_video_id(normalized_input)

    metadata_url, document = resolve_metadata_page(
        normalized_input,
        timeout=timeout,
        retries=retries,
    )

    resolved_video_id = extract_video_id(metadata_url)
    if resolved_video_id != input_video_id:
        raise BuilderError(
            "Resolved watch page video ID does not match input video ID."
        )

    return VideoMetadata(
        source_url=metadata_url,
        video_id=input_video_id,
        title=extract_title(document),
        description=extract_meta(document, "og:description"),
        thumbnail=extract_thumbnail(document),
        duration=extract_duration(document),
        tags=extract_tags(document),
    )


def build_slug(metadata: VideoMetadata) -> str:
    return f"{VIDEO_SOURCE}-{metadata.video_id}"


def metadata_to_row(
    metadata: VideoMetadata,
    category: str,
) -> dict[str, object]:
    return {
        "title": metadata.title,
        "slug": build_slug(metadata),
        "description": metadata.description,
        "embed_url": (
            "https://www.xnxx.com/embedframe/"
            f"{metadata.video_id}"
        ),
        "video_source": VIDEO_SOURCE,
        "thumbnail": metadata.thumbnail,
        "duration": metadata.duration,
        "category": category,
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
        if not source_term_is_ascii_safe(tag):
            continue

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

    with path.open(
        "w",
        encoding="utf-8-sig",
        newline="",
    ) as handle:
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
        else input_path.parent
        / f"xurvexa-xnxx-{category}-{timestamp}.csv"
    )

    rejects_path = (
        Path(rejects_arg).expanduser()
        if rejects_arg
        else output_path.with_name(
            output_path.stem + "-rejected.csv"
        )
    )

    source_terms_path = (
        Path(source_terms_arg).expanduser()
        if source_terms_arg
        else output_path.with_name(
            output_path.stem + "-source-terms.csv"
        )
    )

    return output_path, rejects_path, source_terms_path


def main() -> int:
    args = parse_args()

    try:
        validate_policy()
        validate_args(args)
        category = validate_category_slug(args.category)
        input_path = Path(args.input).expanduser()
        urls = read_urls(input_path)

        (
            output_path,
            rejects_path,
            source_terms_path,
        ) = default_paths(
            input_path=input_path,
            category=category,
            output_arg=args.output,
            rejects_arg=args.rejects,
            source_terms_arg=args.source_terms,
        )

        rows: list[dict[str, object]] = []
        rejects: list[dict[str, object]] = []
        source_term_rows: list[dict[str, object]] = []
        seen_video_ids: set[str] = set()

        print(f"INPUT_URLS={len(urls)}")
        print(f"CATEGORY={category}")
        print(f"VIDEO_SOURCE={VIDEO_SOURCE}")
        print(f"MAX_ACCEPTED={args.max_accepted}")
        print(f"POLICY_VERSION={POLICY_VERSION}")
        print(f"POLICY_TERMS={blocked_term_count()}")

        for index, raw_url in enumerate(urls, start=1):
            if (
                args.max_accepted > 0
                and len(rows) >= args.max_accepted
            ):
                print("STOP_REASON=MAX_ACCEPTED_REACHED")
                break

            video_id = ""

            try:
                normalized = validate_xnxx_url(raw_url)
                video_id = extract_video_id(normalized)

                if video_id in seen_video_ids:
                    raise BuilderError("Duplicate video ID in input.")

                metadata = build_metadata(
                    normalized,
                    timeout=args.timeout,
                    retries=max(1, args.retries),
                )

                policy_reason = metadata_policy_reason(metadata)
                if policy_reason:
                    raise BuilderError(policy_reason)

                rows.append(metadata_to_row(metadata, category))
                source_term_rows.extend(
                    metadata_to_source_terms(metadata)
                )
                seen_video_ids.add(video_id)

                print(
                    f"[{index}/{len(urls)}] OK "
                    f"id={video_id} "
                    f"duration={metadata.duration} "
                    f"tags={len(metadata.tags)} "
                    f"title={metadata.title}"
                )

            except Exception as exc:
                reason = normalize_whitespace(str(exc))
                rejects.append(
                    {
                        "url": raw_url,
                        "video_id": video_id,
                        "reason": reason,
                    }
                )
                print(
                    f"[{index}/{len(urls)}] REJECT "
                    f"id={video_id or '-'} "
                    f"reason={reason}",
                    file=sys.stderr,
                )

            if (
                index < len(urls)
                and args.delay > 0
                and not (
                    args.max_accepted > 0
                    and len(rows) >= args.max_accepted
                )
            ):
                time.sleep(args.delay)

        if not rows:
            if rejects:
                write_csv(
                    rejects_path,
                    REJECT_HEADERS,
                    rejects,
                )
            raise BuilderError("No importable rows were produced.")

        write_csv(output_path, CANONICAL_HEADERS, rows)
        write_csv(
            source_terms_path,
            SOURCE_TERM_HEADERS,
            source_term_rows,
        )

        if rejects:
            write_csv(
                rejects_path,
                REJECT_HEADERS,
                rejects,
            )

        print()
        print(f"CSV_CREATED={output_path.resolve()}")
        print(f"SOURCE_TERMS_FILE={source_terms_path.resolve()}")
        print(f"ROWS_ACCEPTED={len(rows)}")
        print(f"ROWS_REJECTED={len(rejects)}")
        print(f"SOURCE_TERMS_COUNT={len(source_term_rows)}")
        print(f"COLUMN_COUNT={len(CANONICAL_HEADERS)}")
        print("HEADERS=" + ",".join(CANONICAL_HEADERS))
        print("SOURCE_TERM_HEADERS=" + ",".join(SOURCE_TERM_HEADERS))

        if rejects:
            print(f"REJECTS_FILE={rejects_path.resolve()}")

        print("BUILD_STATUS=PASS")
        return 0

    except BuilderError as exc:
        print(f"BUILD_STATUS=FAIL: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
