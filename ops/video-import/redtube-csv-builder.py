#!/usr/bin/env python3
"""
Xurvexa RedTube canonical CSV builder.

Reads public RedTube watch-page URLs, extracts public metadata, applies
Xurvexa's global exclusion policy, writes the canonical 14-column
VideoImporter CSV, and writes a source-term sidecar CSV containing the
source site's video-specific taxonomy/category anchors for later insertion
into video_source_terms. Generic page keywords are only a fallback and
non-informative terms such as 'porn' and 'sex' are discarded.

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
from dataclasses import dataclass, replace
from pathlib import Path
from typing import Any

from redtube_thumbnail_storage import (
    ThumbnailPersistenceError,
    stabilize_thumbnail,
)

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

VIDEO_SOURCE = "redtube"

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/151.0.0.0 Safari/537.36"
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
    tags: tuple[str, ...]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Build Xurvexa canonical CSV from RedTube URLs."
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


def validate_redtube_url(url: str) -> str:
    parsed = urllib.parse.urlparse(url.strip())
    if parsed.scheme not in {"http", "https"}:
        raise BuilderError("URL must use http or https.")

    host = (parsed.hostname or "").lower()
    if host not in {"redtube.com", "www.redtube.com"}:
        raise BuilderError(
            f"Unsupported host '{host}'. Expected www.redtube.com."
        )

    path = parsed.path.rstrip("/")
    if not re.fullmatch(r"/[0-9]+", path):
        raise BuilderError(
            "RedTube watch URL must be a numeric path such as /191008181."
        )

    return urllib.parse.urlunparse(
        ("https", "www.redtube.com", path, "", "", "")
    )


def extract_video_id(url: str) -> str:
    parsed = urllib.parse.urlparse(url.strip())
    path = parsed.path.rstrip("/")
    match = re.fullmatch(r"/([0-9]+)", path)
    if match:
        return match.group(1)

    query = urllib.parse.parse_qs(parsed.query)
    if "id" in query and query["id"]:
        candidate = query["id"][0]
        if re.fullmatch(r"[0-9]+", candidate):
            return candidate

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
    normalized = validate_redtube_url(input_url)
    return normalized, fetch_html(normalized, timeout, retries)


def parse_html_attributes(tag: str, *, raw_content: bool = False) -> dict[str, str]:
    attributes: dict[str, str] = {}

    for match in re.finditer(
        r"([:\w-]+)\s*=\s*([\"'])(.*?)\2",
        tag,
        flags=re.IGNORECASE | re.DOTALL,
    ):
        key = match.group(1).lower()
        value = (
            match.group(3)
            if raw_content and key == "content"
            else normalize_whitespace(html.unescape(match.group(3)))
        )
        attributes[key] = value

    return attributes


def extract_meta(document: str, name: str, *, raw: bool = False) -> str:
    target = name.lower()

    for meta_tag in re.findall(
        r"<meta\b[^>]*>",
        document,
        flags=re.IGNORECASE | re.DOTALL,
    ):
        attributes = parse_html_attributes(meta_tag, raw_content=raw)
        marker = (
            attributes.get("property", "").lower()
            or attributes.get("name", "").lower()
        )
        if marker == target:
            return attributes.get("content", "")

    return ""


def extract_jsonld_objects(document: str, *, raw: bool = False) -> list[Any]:
    objects: list[Any] = []

    for match in re.finditer(
        r'<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>',
        document,
        flags=re.IGNORECASE | re.DOTALL,
    ):
        # Title extraction must inspect JSON string values before HTML decoding.
        payload = match.group(1) if raw else html.unescape(match.group(1)).strip()
        if not payload:
            continue

        try:
            # Keep literal controls in string values for the title gate to reject.
            decoded = json.loads(payload, strict=not raw)
        except json.JSONDecodeError:
            if not raw:
                continue
            # Legacy entity-quoted JSON: decode quote delimiters only. Leave
            # title entities untouched so preflight sees their original values.
            quoted_payload = re.sub(r"&(?:quot;|#0*34;|#[xX]0*22;)", '"', payload)
            try:
                decoded = json.loads(quoted_payload, strict=False)
            except json.JSONDecodeError:
                continue

        objects.append(decoded)

    return objects


def walk_json(value: Any):
    if isinstance(value, dict):
        yield value
        for child in value.values():
            yield from walk_json(child)
    elif isinstance(value, list):
        for child in value:
            yield from walk_json(child)


def jsonld_values(document: str, key: str, *, raw: bool = False) -> list[Any]:
    values: list[Any] = []
    target = key.lower()

    for root in extract_jsonld_objects(document, raw=raw):
        for node in walk_json(root):
            for node_key, node_value in node.items():
                if str(node_key).lower() == target:
                    values.append(node_value)

    return values


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

    for value in jsonld_values(document, "duration"):
        if isinstance(value, str):
            parsed = parse_iso8601_duration(value)
            if parsed:
                return parsed

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

    for key in ("name", "headline"):
        for value in jsonld_values(document, key, raw=True):
            if value is not None and value != "":
                return prepare_source_title(value)

    match = re.search(
        r"<title[^>]*>(.*?)</title>",
        document,
        flags=re.IGNORECASE | re.DOTALL,
    )
    if match:
        title = match.group(1)
        if title:
            return prepare_source_title(title)

    raise BuilderError("Title metadata could not be extracted.")


def extract_description(document: str) -> str:
    description = extract_meta(document, "og:description")
    if description:
        return description

    description = extract_meta(document, "description")
    if description:
        return description

    for value in jsonld_values(document, "description"):
        if isinstance(value, str):
            cleaned = normalize_whitespace(html.unescape(value))
            if cleaned:
                return cleaned

    return ""


def extract_thumbnail(document: str) -> str:
    thumbnail = extract_meta(document, "og:image")

    if not thumbnail:
        for key in ("thumbnailUrl", "thumbnail"):
            for value in jsonld_values(document, key):
                candidate = ""
                if isinstance(value, str):
                    candidate = value
                elif isinstance(value, list) and value and isinstance(value[0], str):
                    candidate = value[0]

                candidate = html.unescape(candidate).strip()
                if candidate.startswith(("https://", "http://")):
                    thumbnail = candidate
                    break
            if thumbnail:
                break

    if not thumbnail.startswith(("https://", "http://")):
        raise BuilderError("Thumbnail metadata is missing or invalid.")
    return thumbnail


GENERIC_SOURCE_TERMS = {"porn", "sex"}
IGNORED_REDTUBE_CATEGORY_SLUGS = {"hd"}


def append_keyword_values(candidates: list[str], value: Any) -> None:
    if isinstance(value, str):
        parts = re.split(r"[,;|]", value)
        for item in parts:
            cleaned = normalize_whitespace(html.unescape(item))
            if cleaned:
                candidates.append(cleaned)
        return

    if isinstance(value, list):
        for item in value:
            append_keyword_values(candidates, item)


def clean_anchor_label(fragment: str) -> str:
    without_tags = re.sub(
        r"<[^>]+>",
        " ",
        fragment,
        flags=re.IGNORECASE | re.DOTALL,
    )
    return normalize_whitespace(html.unescape(without_tags))


def dedupe_source_terms(candidates: list[str]) -> tuple[str, ...]:
    deduped: list[str] = []
    seen: set[str] = set()

    for term in candidates:
        normalized = normalize_source_term(term)
        if not normalized:
            continue
        if normalized in GENERIC_SOURCE_TERMS:
            continue
        if normalized in seen:
            continue

        seen.add(normalized)
        deduped.append(term)

    return tuple(deduped)


def extract_redtube_taxonomy_tags(document: str) -> tuple[str, ...]:
    """Extract video-specific RedTube category anchors.

    RedTube watch pages expose a per-video category block that starts with
    the /categories link, contains /redtube/<category> anchors, and is
    followed by a global /categories/popular... section. We intentionally
    stop at that global boundary so site-wide navigation categories are not
    mistaken for metadata belonging to the current video.
    """

    candidates: list[str] = []
    inside_video_category_block = False

    for match in re.finditer(
        r"<a\b(?P<attrs>[^>]*)>(?P<body>.*?)</a>",
        document,
        flags=re.IGNORECASE | re.DOTALL,
    ):
        attributes = parse_html_attributes(
            "<a" + match.group("attrs") + ">"
        )
        href = html.unescape(attributes.get("href", "")).strip()
        if not href:
            continue

        parsed = urllib.parse.urlparse(href)
        path = parsed.path.rstrip("/") or "/"
        path_lower = path.lower()

        if path_lower == "/categories":
            inside_video_category_block = True
            continue

        if not inside_video_category_block:
            continue

        if path_lower.startswith("/categories/popular"):
            break

        category_match = re.fullmatch(
            r"/redtube/([a-z0-9_-]+)",
            path_lower,
        )
        if not category_match:
            continue

        category_slug = category_match.group(1)
        if category_slug in IGNORED_REDTUBE_CATEGORY_SLUGS:
            continue

        label = clean_anchor_label(match.group("body"))
        if not label:
            label = category_slug.replace("-", " ").replace("_", " ")
            label = normalize_whitespace(label)

        if label:
            candidates.append(label)

    return dedupe_source_terms(candidates)


def extract_tags(document: str) -> tuple[str, ...]:
    taxonomy_tags = extract_redtube_taxonomy_tags(document)
    if taxonomy_tags:
        return taxonomy_tags

    # Fallback only: if RedTube changes its watch-page taxonomy markup,
    # retain support for genuinely useful keyword metadata. Generic page
    # keywords such as "porn" and "sex" are deliberately discarded so
    # a markup regression fails closed instead of silently degrading
    # taxonomy quality.
    candidates: list[str] = []

    keywords = extract_meta(document, "keywords")
    if keywords:
        append_keyword_values(candidates, keywords)

    for value in jsonld_values(document, "keywords"):
        append_keyword_values(candidates, value)

    return dedupe_source_terms(candidates)


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
    normalized_input = validate_redtube_url(url)
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

    tags = extract_tags(document)
    if not tags:
        raise BuilderError(
            "No usable RedTube video taxonomy/source terms were extracted."
        )

    return VideoMetadata(
        source_url=metadata_url,
        video_id=input_video_id,
        title=extract_title(document),
        description=extract_description(document),
        thumbnail=extract_thumbnail(document),
        duration=extract_duration(document),
        tags=tags,
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
            "https://embed.redtube.com/?id="
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
        / f"xurvexa-redtube-{category}-{timestamp}.csv"
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
        thumbnail_stabilized_count = 0

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
                normalized = validate_redtube_url(raw_url)
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

                source_rows = metadata_to_source_terms(metadata)
                if not source_rows:
                    raise BuilderError(
                        "No ASCII-safe source terms remained after normalization."
                    )

                try:
                    stabilized_thumbnail, thumbnail_changed = (
                        stabilize_thumbnail(
                            video_id=metadata.video_id,
                            thumbnail_url=metadata.thumbnail,
                            timeout=args.timeout,
                            retries=max(1, args.retries),
                        )
                    )
                except ThumbnailPersistenceError as exc:
                    raise BuilderError(
                        "Thumbnail stabilization failed: "
                        + str(exc)
                    ) from exc

                if thumbnail_changed:
                    metadata = replace(
                        metadata,
                        thumbnail=stabilized_thumbnail,
                    )
                    thumbnail_stabilized_count += 1

                rows.append(metadata_to_row(metadata, category))
                source_term_rows.extend(source_rows)
                seen_video_ids.add(video_id)

                print(
                    f"[{index}/{len(urls)}] OK "
                    f"id={video_id} "
                    f"duration={metadata.duration} "
                    f"tags={len(metadata.tags)} "
                    f"thumbnail="
                    f"{'stabilized' if thumbnail_changed else 'provider'} "
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
        print(
            "THUMBNAIL_STABILIZED_COUNT="
            + str(thumbnail_stabilized_count)
        )
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
