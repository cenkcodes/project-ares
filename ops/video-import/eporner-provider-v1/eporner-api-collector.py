#!/usr/bin/env python3
"""
Xurvexa Eporner API collector.

Collects public Eporner video IDs from the official Eporner API v2 search
endpoint and writes one source video ID per line for the downstream
eporner-csv-builder.py tool.

The shared Xurvexa video_import_policy.py is applied to the requested query
and to search-result title/keywords as an early pass. The builder performs
the authoritative second policy pass against detail metadata.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

from video_import_policy import (
    POLICY_VERSION,
    blocked_term_count,
    matched_blocked_term,
    validate_policy,
)

API_SEARCH = "https://www.eporner.com/api/v2/video/search/"
USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/151.0.0.0 Safari/537.36"
)
VIDEO_ID_RE = re.compile(r"^[A-Za-z0-9_-]+$")


class CollectorError(RuntimeError):
    pass


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Collect public Eporner video IDs for Xurvexa bulk import."
    )
    parser.add_argument("--query", default="amateur")
    parser.add_argument("--target", type=int, default=700)
    parser.add_argument("--output", required=True)
    parser.add_argument("--exclude-ids")
    parser.add_argument("--start-page", type=int, default=1)
    parser.add_argument("--max-pages", type=int, default=40)
    parser.add_argument("--per-page", type=int, default=250)
    parser.add_argument("--timeout", type=int, default=30)
    parser.add_argument("--retries", type=int, default=3)
    parser.add_argument("--delay", type=float, default=0.30)
    parser.add_argument(
        "--order",
        default="latest",
        choices=[
            "latest",
            "longest",
            "shortest",
            "top-rated",
            "most-popular",
            "top-weekly",
            "top-monthly",
        ],
    )
    return parser.parse_args()


def validate_args(args: argparse.Namespace) -> None:
    if args.target <= 0:
        raise CollectorError("--target must be greater than zero.")
    if args.start_page < 1:
        raise CollectorError("--start-page must be at least 1.")
    if args.max_pages <= 0:
        raise CollectorError("--max-pages must be greater than zero.")
    if args.per_page < 1 or args.per_page > 1000:
        raise CollectorError("--per-page must be between 1 and 1000.")
    if args.timeout <= 0:
        raise CollectorError("--timeout must be greater than zero.")
    if args.retries <= 0:
        raise CollectorError("--retries must be greater than zero.")
    if args.delay < 0:
        raise CollectorError("--delay cannot be negative.")

    query_block = matched_blocked_term(args.query)
    if query_block:
        raise CollectorError(
            f"Query is blocked by global taxonomy policy: {query_block}"
        )


def normalize_id(value: str) -> str:
    value = value.strip()
    if not VIDEO_ID_RE.fullmatch(value):
        raise CollectorError(f"Invalid Eporner video ID: {value}")
    return value


def read_excluded_ids(path: Path | None) -> set[str]:
    if path is None:
        return set()
    if not path.is_file():
        raise CollectorError(f"Exclude file not found: {path}")

    excluded: set[str] = set()
    for raw_line in path.read_text(encoding="utf-8-sig").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue
        try:
            video_id = normalize_id(line)
        except CollectorError:
            continue
        excluded.add(video_id.lower())
    return excluded


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
                    raise CollectorError(
                        f"Unexpected HTTP status {status} for {url}"
                    )
                payload = response.read()
                if not payload:
                    raise CollectorError(f"Empty response body for {url}")
                decoded = json.loads(payload.decode("utf-8"))
                if not isinstance(decoded, dict):
                    raise CollectorError("API response is not a JSON object.")
                return decoded
        except (
            urllib.error.URLError,
            urllib.error.HTTPError,
            TimeoutError,
            json.JSONDecodeError,
            CollectorError,
        ) as exc:
            last_error = exc
            if attempt < retries:
                time.sleep(min(2.0 * attempt, 5.0))

    raise CollectorError(
        f"API fetch failed after {retries} attempt(s): {last_error}"
    )


def build_search_url(
    query: str,
    page: int,
    per_page: int,
    order: str,
) -> str:
    params = urllib.parse.urlencode(
        {
            "query": query,
            "per_page": per_page,
            "page": page,
            "thumbsize": "big",
            "order": order,
            "gay": 0,
            "lq": 1,
            "format": "json",
        }
    )
    return API_SEARCH + "?" + params


def keywords_text(value: object) -> str:
    if isinstance(value, str):
        return value
    if isinstance(value, list):
        return " ".join(str(item) for item in value if item is not None)
    return ""


def blocked_reason(video: dict) -> str | None:
    for field_name, raw_value in (
        ("title", video.get("title")),
        ("keywords", keywords_text(video.get("keywords"))),
        ("url", video.get("url")),
    ):
        value = "" if raw_value is None else str(raw_value)
        term = matched_blocked_term(value)
        if term:
            return f"{field_name}:{term}"
    return None


def write_ids(path: Path, ids: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        "\n".join(ids) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def main() -> int:
    args = parse_args()

    try:
        validate_policy()
        validate_args(args)

        output_path = Path(args.output).expanduser()
        exclude_path = (
            Path(args.exclude_ids).expanduser()
            if args.exclude_ids
            else None
        )
        excluded_ids = read_excluded_ids(exclude_path)

        accepted_ids: list[str] = []
        seen_ids: set[str] = set(excluded_ids)

        total_found = 0
        total_blocked = 0
        total_duplicate = 0
        pages_processed = 0
        total_pages_reported = None

        print(f"QUERY={args.query}")
        print(f"TARGET={args.target}")
        print(f"EXCLUDED_IDS={len(excluded_ids)}")
        print(f"START_PAGE={args.start_page}")
        print(f"MAX_PAGES={args.max_pages}")
        print(f"PER_PAGE={args.per_page}")
        print(f"ORDER={args.order}")
        print(f"POLICY_VERSION={POLICY_VERSION}")
        print(f"POLICY_TERMS={blocked_term_count()}")

        for page in range(
            args.start_page,
            args.start_page + args.max_pages,
        ):
            if len(accepted_ids) >= args.target:
                break

            if (
                total_pages_reported is not None
                and page > total_pages_reported
            ):
                print("STOP_REASON=API_TOTAL_PAGES_EXHAUSTED")
                break

            document = fetch_json(
                build_search_url(
                    args.query,
                    page,
                    args.per_page,
                    args.order,
                ),
                timeout=args.timeout,
                retries=args.retries,
            )

            videos = document.get("videos")
            if not isinstance(videos, list):
                raise CollectorError("API response has no videos array.")

            reported = document.get("total_pages")
            if isinstance(reported, int) and reported > 0:
                total_pages_reported = reported

            pages_processed += 1
            total_found += len(videos)

            page_added = 0
            page_blocked = 0
            page_duplicate = 0

            for video in videos:
                if not isinstance(video, dict):
                    continue

                raw_id = video.get("id")
                if not isinstance(raw_id, str):
                    continue

                try:
                    video_id = normalize_id(raw_id)
                except CollectorError:
                    continue

                id_key = video_id.lower()

                if id_key in seen_ids:
                    total_duplicate += 1
                    page_duplicate += 1
                    continue

                reason = blocked_reason(video)
                if reason is not None:
                    total_blocked += 1
                    page_blocked += 1
                    seen_ids.add(id_key)
                    continue

                accepted_ids.append(video_id)
                seen_ids.add(id_key)
                page_added += 1

                if len(accepted_ids) >= args.target:
                    break

            print(
                f"PAGE={page} "
                f"FOUND={len(videos)} "
                f"ADDED={page_added} "
                f"BLOCKED={page_blocked} "
                f"DUPLICATE={page_duplicate} "
                f"TOTAL={len(accepted_ids)}"
            )

            if not videos:
                print("STOP_REASON=EMPTY_API_PAGE")
                break

            if len(accepted_ids) < args.target and args.delay > 0:
                time.sleep(args.delay)

        if not accepted_ids:
            raise CollectorError("No usable Eporner candidate IDs were collected.")

        write_ids(output_path, accepted_ids)

        print()
        print(f"OUTPUT={output_path.resolve()}")
        print(f"IDS_COLLECTED={len(accepted_ids)}")
        print(f"PAGES_PROCESSED={pages_processed}")
        print(f"TOTAL_FOUND={total_found}")
        print(f"TOTAL_BLOCKED={total_blocked}")
        print(f"TOTAL_DUPLICATE={total_duplicate}")

        if len(accepted_ids) >= args.target:
            print("COLLECT_STATUS=PASS")
            return 0

        print(
            "COLLECT_STATUS=PARTIAL: "
            f"requested={args.target} collected={len(accepted_ids)}",
            file=sys.stderr,
        )
        return 2

    except CollectorError as exc:
        print(f"COLLECT_STATUS=FAIL: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
