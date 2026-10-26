#!/usr/bin/env python3
"""
PornTrex canonical CSV builder.

Reads public PornTrex watch-page URLs, extracts public metadata, applies the
shared video import policy, writes the canonical 14-column VideoImporter CSV,
and writes a source-term sidecar CSV for taxonomy reconciliation.

Architecture:
    porntrex-url-collector.py
        -> porntrex-csv-builder.py
        -> shared video_import_policy.py
        -> canonical VideoImporter CSV
        -> Filament VideoImporter
        -> import-video-source-terms.php
        -> integrity checks

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

REJECT_HEADERS = [
    "url",
    "video_id",
    "reason",
]

SOURCE_TERM_HEADERS = [
    "video_slug",
    "video_source",
    "term_type",
    "term",
    "normalized_term",
]

VIDEO_SOURCE = "porntrex"

BASE_URL = "https://www.porntrex.com"

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/151.0.0.0 Safari/537.36"
)

VIDEO_ID_PATTERNS = (
    re.compile(
        r"/video/([0-9]+)/",
        re.IGNORECASE,
    ),
    re.compile(
        r"/embed/([0-9]+)",
        re.IGNORECASE,
    ),
)


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
    categories: tuple[str, ...]
    tags: tuple[str, ...]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Build canonical VideoImporter CSV "
            "from PornTrex URLs."
        )
    )

    parser.add_argument(
        "--input",
        required=True,
        help="Text file containing one PornTrex URL per line.",
    )

    parser.add_argument(
        "--category",
        required=True,
        help=(
            "Existing canonical category slug, "
            "for example big-ass or anal."
        ),
    )

    parser.add_argument(
        "--output",
        help="Canonical output CSV path.",
    )

    parser.add_argument(
        "--rejects",
        help="Rejected-items CSV path.",
    )

    parser.add_argument(
        "--source-terms",
        help="Source-term sidecar CSV path.",
    )

    parser.add_argument(
        "--max-accepted",
        type=int,
        default=0,
        help=(
            "Maximum accepted rows. "
            "0 means no accepted-row limit."
        ),
    )

    parser.add_argument(
        "--timeout",
        type=int,
        default=30,
        help="HTTP timeout in seconds. Default: 30.",
    )

    parser.add_argument(
        "--retries",
        type=int,
        default=3,
        help="HTTP attempts per URL. Default: 3.",
    )

    parser.add_argument(
        "--delay",
        type=float,
        default=0.75,
        help="Delay between URLs. Default: 0.75.",
    )

    return parser.parse_args()


def normalize_whitespace(
    value: str,
) -> str:
    return re.sub(
        r"\s+",
        " ",
        value,
    ).strip()


def normalize_source_term(
    value: str,
) -> str:
    value = html.unescape(
        value
    )

    value = value.translate(
        str.maketrans(
            {
                "\u2018": "'",
                "\u2019": "'",
                "\u02bc": None,
                "\uff07": None,
                "\u00b4": None,
                "\u02bb": None,
                "\u02b9": None,
                "\u2032": None,
            }
        )
    )

    value = unicodedata.normalize(
        "NFKD",
        value,
    )

    value = value.encode(
        "ascii",
        "ignore",
    ).decode(
        "ascii"
    )

    value = value.lower()

    value = re.sub(
        r"[^a-z0-9]+",
        "-",
        value,
    )

    value = re.sub(
        r"-+",
        "-",
        value,
    )

    return value.strip("-")


def source_term_is_ascii_safe(
    value: str,
) -> bool:
    value = html.unescape(
        value
    )

    value = unicodedata.normalize(
        "NFKD",
        value,
    )

    return not any(
        ord(character) > 127
        and unicodedata.category(
            character
        )[0] in {"L", "N"}
        for character in value
    )


def validate_category_slug(
    category: str,
) -> str:
    category = category.strip().lower()

    if not re.fullmatch(
        r"[a-z0-9]+(?:-[a-z0-9]+)*",
        category,
    ):
        raise BuilderError(
            "Category must be an existing "
            "lowercase canonical slug."
        )

    return category


def validate_args(
    args: argparse.Namespace,
) -> None:
    if args.timeout <= 0:
        raise BuilderError(
            "--timeout must be greater than zero."
        )

    if args.retries <= 0:
        raise BuilderError(
            "--retries must be greater than zero."
        )

    if args.delay < 0:
        raise BuilderError(
            "--delay cannot be negative."
        )

    if args.max_accepted < 0:
        raise BuilderError(
            "--max-accepted cannot be negative."
        )


def read_urls(
    path: Path,
) -> list[str]:
    if not path.is_file():
        raise BuilderError(
            f"Input file not found: {path}"
        )

    urls: list[str] = []
    seen: set[str] = set()

    for raw_line in path.read_text(
        encoding="utf-8-sig"
    ).splitlines():
        line = raw_line.strip()

        if (
            not line
            or line.startswith("#")
        ):
            continue

        if line not in seen:
            urls.append(
                line
            )
            seen.add(
                line
            )

    if not urls:
        raise BuilderError(
            "Input file contains no usable URLs."
        )

    return urls


def validate_porntrex_url(
    url: str,
) -> str:
    parsed = urllib.parse.urlparse(
        url.strip()
    )

    if parsed.scheme not in {
        "http",
        "https",
    }:
        raise BuilderError(
            "URL must use http or https."
        )

    host = (
        parsed.hostname
        or ""
    ).lower()

    if host not in {
        "porntrex.com",
        "www.porntrex.com",
    }:
        raise BuilderError(
            f"Unsupported host '{host}'. "
            "Expected www.porntrex.com."
        )

    if not re.match(
        r"^/(?:video|embed)/[0-9]+(?:/|$)",
        parsed.path,
        flags=re.IGNORECASE,
    ):
        raise BuilderError(
            "Unsupported PornTrex URL path."
        )

    return urllib.parse.urlunparse(
        (
            "https",
            "www.porntrex.com",
            parsed.path,
            "",
            "",
            "",
        )
    )


def extract_video_id(
    value: str,
) -> str:
    for pattern in VIDEO_ID_PATTERNS:
        match = pattern.search(
            value
        )

        if match:
            return match.group(1)

    raise BuilderError(
        "PornTrex video ID could not "
        "be extracted from URL."
    )


def fetch_html(
    url: str,
    timeout: int,
    retries: int,
) -> str:
    last_error: Exception | None = None

    for attempt in range(
        1,
        retries + 1,
    ):
        request = urllib.request.Request(
            url,
            headers={
                "User-Agent": USER_AGENT,
                "Accept": (
                    "text/html,"
                    "application/xhtml+xml,"
                    "application/xml;q=0.9,"
                    "*/*;q=0.8"
                ),
                "Accept-Language": (
                    "en-US,en;q=0.9"
                ),
                "Cache-Control": "no-cache",
                "Pragma": "no-cache",
            },
        )

        try:
            with urllib.request.urlopen(
                request,
                timeout=timeout,
            ) as response:
                status = getattr(
                    response,
                    "status",
                    200,
                )

                if status != 200:
                    raise BuilderError(
                        "Unexpected HTTP status "
                        f"{status} for {url}"
                    )

                content_type = response.headers.get(
                    "Content-Type",
                    "",
                ).lower()

                if (
                    content_type
                    and "text/html"
                    not in content_type
                ):
                    raise BuilderError(
                        "Unexpected content type: "
                        f"{content_type}"
                    )

                payload = response.read()

                if not payload:
                    raise BuilderError(
                        "Downloaded page is empty."
                    )

                return payload.decode(
                    "utf-8",
                    errors="replace",
                )

        except (
            urllib.error.URLError,
            urllib.error.HTTPError,
            TimeoutError,
            BuilderError,
        ) as exc:
            last_error = exc

            if attempt < retries:
                time.sleep(
                    min(
                        2.0 * attempt,
                        5.0,
                    )
                )

    raise BuilderError(
        "Page download failed after "
        f"{retries} attempt(s): "
        f"{last_error}"
    )


def extract_js_string(
    document: str,
    key: str,
) -> str:
    pattern = re.compile(
        rf"\b{re.escape(key)}\s*:\s*"
        r"'((?:\\.|[^'\\])*)'",
        flags=re.IGNORECASE
        | re.DOTALL,
    )

    match = pattern.search(
        document
    )

    if not match:
        return ""

    value = match.group(1)

    value = value.replace(
        r"\/",
        "/",
    )

    value = value.replace(
        r"\'",
        "'",
    )

    value = value.replace(
        r"\\",
        "\\",
    )

    value = html.unescape(
        value
    )

    return normalize_whitespace(
        value
    )


def split_source_terms(
    value: str,
) -> tuple[str, ...]:
    candidates: list[str] = []
    seen: set[str] = set()

    for raw in value.split(","):
        term = normalize_whitespace(
            html.unescape(raw)
        )

        if not term:
            continue

        normalized = normalize_source_term(
            term
        )

        if not normalized:
            continue

        if normalized in seen:
            continue

        seen.add(
            normalized
        )

        candidates.append(
            term
        )

    return tuple(
        candidates
    )


def extract_title(
    document: str,
) -> str:
    title = extract_js_string(
        document,
        "video_title",
    )

    if not title:
        raise BuilderError(
            "Title metadata could not "
            "be extracted."
        )

    return title


def extract_categories(
    document: str,
) -> tuple[str, ...]:
    value = extract_js_string(
        document,
        "video_categories",
    )

    return split_source_terms(
        value
    )


def extract_tags(
    document: str,
) -> tuple[str, ...]:
    value = extract_js_string(
        document,
        "video_tags",
    )

    return split_source_terms(
        value
    )


def extract_thumbnail(
    document: str,
) -> str:
    thumbnail = extract_js_string(
        document,
        "preview_url",
    )

    if thumbnail.startswith("//"):
        thumbnail = (
            "https:"
            + thumbnail
        )

    elif thumbnail.startswith("/"):
        thumbnail = urllib.parse.urljoin(
            BASE_URL + "/",
            thumbnail,
        )

    if not thumbnail.startswith(
        (
            "https://",
            "http://",
        )
    ):
        raise BuilderError(
            "Thumbnail metadata is "
            "missing or invalid."
        )

    return thumbnail


def extract_duration(
    document: str,
) -> int:
    match = re.search(
        r'<i[^>]+class=["\'][^"\']*'
        r'fa-clock-o[^"\']*["\'][^>]*>'
        r'\s*</i>\s*'
        r'<em[^>]*class=["\'][^"\']*'
        r'badge[^"\']*["\'][^>]*>'
        r'\s*'
        r'(?P<minutes>[0-9]+)'
        r'min'
        r'(?:\s+'
        r'(?P<seconds>[0-9]+)'
        r'sec'
        r')?'
        r'\s*</em>',
        document,
        flags=re.IGNORECASE,
    )

    if not match:
        raise BuilderError(
            "Duration metadata could not "
            "be extracted."
        )

    minutes = int(
        match.group(
            "minutes"
        )
    )

    seconds = int(
        match.group(
            "seconds"
        )
        or 0
    )

    total = (
        minutes * 60
        + seconds
    )

    if total <= 0:
        raise BuilderError(
            "Duration metadata is invalid."
        )

    return total


def extract_description(
    document: str,
) -> str:
    match = re.search(
        r'<div[^>]+class=["\'][^"\']*'
        r'videodesc[^"\']*["\'][^>]*>'
        r'.*?'
        r'<em[^>]+class=["\'][^"\']*'
        r'des-link[^"\']*["\'][^>]*>'
        r'(.*?)'
        r'</em>',
        document,
        flags=re.IGNORECASE
        | re.DOTALL,
    )

    if not match:
        return ""

    value = re.sub(
        r"<[^>]+>",
        " ",
        match.group(1),
        flags=re.IGNORECASE
        | re.DOTALL,
    )

    return normalize_whitespace(
        html.unescape(value)
    )


def metadata_policy_reason(
    metadata: VideoMetadata,
) -> str | None:
    for field_name, value in (
        (
            "title",
            metadata.title,
        ),
        (
            "description",
            metadata.description,
        ),
    ):
        term = matched_blocked_term(
            value
        )

        if term:
            return (
                "Blocked by global policy "
                f"term '{term}' "
                f"in {field_name}."
            )

    for category in metadata.categories:
        term = matched_blocked_term(
            category
        )

        if term:
            return (
                "Blocked by global policy "
                f"term '{term}' "
                "in source category "
                f"'{category}'."
            )

    for tag in metadata.tags:
        term = matched_blocked_term(
            tag
        )

        if term:
            return (
                "Blocked by global policy "
                f"term '{term}' "
                f"in source tag '{tag}'."
            )

    return None


def build_metadata(
    url: str,
    timeout: int,
    retries: int,
) -> VideoMetadata:
    normalized = validate_porntrex_url(
        url
    )

    input_video_id = extract_video_id(
        normalized
    )

    watch_url = (
        f"{BASE_URL}/video/"
        f"{input_video_id}/"
    )

    parsed = urllib.parse.urlparse(
        normalized
    )

    if parsed.path.lower().startswith(
        "/video/"
    ):
        watch_url = normalized

    document = fetch_html(
        watch_url,
        timeout=timeout,
        retries=retries,
    )

    page_video_id = extract_js_string(
        document,
        "video_id",
    )

    if not page_video_id:
        raise BuilderError(
            "PornTrex page did not expose "
            "video_id metadata."
        )

    if page_video_id != input_video_id:
        raise BuilderError(
            "PornTrex page video ID does not "
            "match input video ID."
        )

    categories = extract_categories(
        document
    )

    tags = extract_tags(
        document
    )

    if (
        not categories
        and not tags
    ):
        raise BuilderError(
            "No usable PornTrex source "
            "taxonomy terms were extracted."
        )

    return VideoMetadata(
        source_url=watch_url,
        video_id=input_video_id,
        title=extract_title(
            document
        ),
        description=extract_description(
            document
        ),
        thumbnail=extract_thumbnail(
            document
        ),
        duration=extract_duration(
            document
        ),
        categories=categories,
        tags=tags,
    )


def build_slug(
    metadata: VideoMetadata,
) -> str:
    return (
        f"{VIDEO_SOURCE}-"
        f"{metadata.video_id}"
    )


def metadata_to_row(
    metadata: VideoMetadata,
    category: str,
) -> dict[str, object]:
    return {
        "title": metadata.title,
        "slug": build_slug(
            metadata
        ),
        "description": (
            metadata.description
        ),
        "embed_url": (
            "https://www.porntrex.com/embed/"
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
    video_slug = build_slug(
        metadata
    )

    rows: list[
        dict[str, str]
    ] = []

    seen: set[
        tuple[str, str]
    ] = set()

    for term_type, terms in (
        (
            "tag",
            metadata.categories,
        ),
        (
            "tag",
            metadata.tags,
        ),
    ):
        for term in terms:
            if not source_term_is_ascii_safe(
                term
            ):
                continue

            normalized = normalize_source_term(
                term
            )

            if not normalized:
                continue

            key = (
                term_type,
                normalized,
            )

            if key in seen:
                continue

            seen.add(
                key
            )

            rows.append(
                {
                    "video_slug": video_slug,
                    "video_source": VIDEO_SOURCE,
                    "term_type": term_type,
                    "term": term,
                    "normalized_term": normalized,
                }
            )

    return rows


def write_csv(
    path: Path,
    fieldnames: list[str],
    rows: list[
        dict[str, object]
    ],
) -> None:
    path.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

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
        writer.writerows(
            rows
        )


def default_paths(
    input_path: Path,
    category: str,
    output_arg: str | None,
    rejects_arg: str | None,
    source_terms_arg: str | None,
) -> tuple[
    Path,
    Path,
    Path,
]:
    timestamp = time.strftime(
        "%Y%m%d-%H%M%S"
    )

    output_path = (
        Path(
            output_arg
        ).expanduser()
        if output_arg
        else input_path.parent
        / (
            f"porntrex-"
            f"{category}-"
            f"{timestamp}.csv"
        )
    )

    rejects_path = (
        Path(
            rejects_arg
        ).expanduser()
        if rejects_arg
        else output_path.with_name(
            output_path.stem
            + "-rejected.csv"
        )
    )

    source_terms_path = (
        Path(
            source_terms_arg
        ).expanduser()
        if source_terms_arg
        else output_path.with_name(
            output_path.stem
            + "-source-terms.csv"
        )
    )

    return (
        output_path,
        rejects_path,
        source_terms_path,
    )


def main() -> int:
    args = parse_args()

    try:
        validate_policy()
        validate_args(
            args
        )

        category = validate_category_slug(
            args.category
        )

        input_path = Path(
            args.input
        ).expanduser()

        urls = read_urls(
            input_path
        )

        (
            output_path,
            rejects_path,
            source_terms_path,
        ) = default_paths(
            input_path=input_path,
            category=category,
            output_arg=args.output,
            rejects_arg=args.rejects,
            source_terms_arg=(
                args.source_terms
            ),
        )

        rows: list[
            dict[str, object]
        ] = []

        rejects: list[
            dict[str, object]
        ] = []

        source_term_rows: list[
            dict[str, object]
        ] = []

        seen_video_ids: set[
            str
        ] = set()

        print(
            f"INPUT_URLS="
            f"{len(urls)}"
        )

        print(
            f"CATEGORY={category}"
        )

        print(
            f"VIDEO_SOURCE="
            f"{VIDEO_SOURCE}"
        )

        print(
            f"MAX_ACCEPTED="
            f"{args.max_accepted}"
        )

        print(
            f"POLICY_VERSION="
            f"{POLICY_VERSION}"
        )

        print(
            f"POLICY_TERMS="
            f"{blocked_term_count()}"
        )

        for index, raw_url in enumerate(
            urls,
            start=1,
        ):
            if (
                args.max_accepted > 0
                and len(rows)
                >= args.max_accepted
            ):
                print(
                    "STOP_REASON="
                    "MAX_ACCEPTED_REACHED"
                )
                break

            video_id = ""

            try:
                normalized = validate_porntrex_url(
                    raw_url
                )

                video_id = extract_video_id(
                    normalized
                )

                if (
                    video_id
                    in seen_video_ids
                ):
                    raise BuilderError(
                        "Duplicate video ID "
                        "in input."
                    )

                metadata = build_metadata(
                    normalized,
                    timeout=args.timeout,
                    retries=max(
                        1,
                        args.retries,
                    ),
                )

                policy_reason = metadata_policy_reason(
                    metadata
                )

                if policy_reason:
                    raise BuilderError(
                        policy_reason
                    )

                source_rows = metadata_to_source_terms(
                    metadata
                )

                if not source_rows:
                    raise BuilderError(
                        "No ASCII-safe source "
                        "terms remained after "
                        "normalization."
                    )

                rows.append(
                    metadata_to_row(
                        metadata,
                        category,
                    )
                )

                source_term_rows.extend(
                    source_rows
                )

                seen_video_ids.add(
                    video_id
                )

                print(
                    f"[{index}/{len(urls)}] "
                    f"OK "
                    f"id={video_id} "
                    f"duration="
                    f"{metadata.duration} "
                    f"categories="
                    f"{len(metadata.categories)} "
                    f"tags="
                    f"{len(metadata.tags)} "
                    f"title="
                    f"{metadata.title}"
                )

            except Exception as exc:
                reason = normalize_whitespace(
                    str(exc)
                )

                rejects.append(
                    {
                        "url": raw_url,
                        "video_id": video_id,
                        "reason": reason,
                    }
                )

                print(
                    f"[{index}/{len(urls)}] "
                    f"REJECT "
                    f"id="
                    f"{video_id or '-'} "
                    f"reason={reason}",
                    file=sys.stderr,
                )

            if (
                index < len(urls)
                and args.delay > 0
                and not (
                    args.max_accepted > 0
                    and len(rows)
                    >= args.max_accepted
                )
            ):
                time.sleep(
                    args.delay
                )

        if not rows:
            if rejects:
                write_csv(
                    rejects_path,
                    REJECT_HEADERS,
                    rejects,
                )

            raise BuilderError(
                "No importable rows "
                "were produced."
            )

        write_csv(
            output_path,
            CANONICAL_HEADERS,
            rows,
        )

        write_csv(
            source_terms_path,
            SOURCE_TERM_HEADERS,
            source_term_rows,
        )

        write_csv(
            rejects_path,
            REJECT_HEADERS,
            rejects,
        )

        print()

        print(
            "CSV_CREATED="
            f"{output_path.resolve()}"
        )

        print(
            "SOURCE_TERMS_FILE="
            f"{source_terms_path.resolve()}"
        )

        print(
            f"ROWS_ACCEPTED="
            f"{len(rows)}"
        )

        print(
            f"ROWS_REJECTED="
            f"{len(rejects)}"
        )

        print(
            f"SOURCE_TERMS_COUNT="
            f"{len(source_term_rows)}"
        )

        print(
            f"COLUMN_COUNT="
            f"{len(CANONICAL_HEADERS)}"
        )

        print(
            "HEADERS="
            + ",".join(
                CANONICAL_HEADERS
            )
        )

        print(
            "SOURCE_TERM_HEADERS="
            + ",".join(
                SOURCE_TERM_HEADERS
            )
        )

        if rejects:
            print(
                "REJECTS_FILE="
                f"{rejects_path.resolve()}"
            )

        print(
            "BUILD_STATUS=PASS"
        )

        return 0

    except (
        BuilderError,
        RuntimeError,
    ) as exc:
        print(
            f"BUILD_STATUS=FAIL: {exc}",
            file=sys.stderr,
        )

        return 1


if __name__ == "__main__":
    raise SystemExit(
        main()
    )