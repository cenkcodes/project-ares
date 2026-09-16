#!/usr/bin/env python3
"""
Xurvexa RedTube URL collector.

Collects public RedTube watch-page URLs from keyword search pages and writes
one canonical URL per line for the downstream redtube-csv-builder.py tool.

The collector applies the same global exclusion vocabulary used by the builder
as an early URL/path filter. The builder performs the authoritative second
policy pass against title, description, and source tags.
"""

from __future__ import annotations

import argparse
import html
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

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/151.0.0.0 Safari/537.36"
)

VIDEO_LINK_PATTERNS = [
    re.compile(
        r'href=["\']((?:https?://(?:www\.)?redtube\.com)?/[0-9]{5,}(?:[?#][^"\']*)?)["\']',
        re.IGNORECASE,
    ),
]

VIDEO_ID_PATTERNS = [
    re.compile(r"https?://(?:www\.)?redtube\.com/([0-9]+)(?:[/?#]|$)", re.IGNORECASE),
    re.compile(r"^/([0-9]+)(?:[/?#]|$)", re.IGNORECASE),
    re.compile(r"[?&]id=([0-9]+)(?:[&#]|$)", re.IGNORECASE),
]


class CollectorError(RuntimeError):
    pass


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Collect public RedTube watch URLs for Xurvexa bulk import."
    )
    parser.add_argument("--query", default="amateur")
    parser.add_argument("--target", type=int, default=700)
    parser.add_argument("--output", required=True)
    parser.add_argument("--exclude-ids")
    parser.add_argument("--start-page", type=int, default=1)
    parser.add_argument("--max-pages", type=int, default=80)
    parser.add_argument("--timeout", type=int, default=30)
    parser.add_argument("--retries", type=int, default=3)
    parser.add_argument("--delay", type=float, default=0.80)
    return parser.parse_args()


def validate_positive_args(args: argparse.Namespace) -> None:
    if args.target <= 0:
        raise CollectorError("--target must be greater than zero.")
    if args.start_page < 1:
        raise CollectorError("--start-page must be at least one.")
    if args.max_pages <= 0:
        raise CollectorError("--max-pages must be greater than zero.")
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


def extract_video_id(value: str) -> str | None:
    candidate = value.strip()

    for pattern in VIDEO_ID_PATTERNS:
        match = pattern.search(candidate)
        if match:
            return match.group(1)

    if re.fullmatch(r"[0-9]+", candidate):
        return candidate

    return None


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
        video_id = extract_video_id(line)
        if video_id:
            excluded.add(video_id)
    return excluded


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
                    raise CollectorError(
                        f"Unexpected HTTP status {status} for {url}"
                    )
                payload = response.read()
                if not payload:
                    raise CollectorError(f"Empty response body for {url}")
                return payload.decode("utf-8", errors="replace")
        except (
            urllib.error.URLError,
            urllib.error.HTTPError,
            TimeoutError,
            CollectorError,
        ) as exc:
            last_error = exc
            if attempt < retries:
                time.sleep(min(2.0 * attempt, 5.0))

    raise CollectorError(
        f"Page fetch failed after {retries} attempt(s): {last_error}"
    )


def build_search_url(query: str, page: int) -> str:
    params = {"search": query.strip()}
    if page > 1:
        params["page"] = str(page)
    return "https://www.redtube.com/?" + urllib.parse.urlencode(params)


def extract_watch_urls(document: str) -> list[str]:
    urls: list[str] = []
    seen: set[str] = set()

    for pattern in VIDEO_LINK_PATTERNS:
        for raw_value in pattern.findall(document):
            value = html.unescape(raw_value).strip()
            video_id = extract_video_id(value)
            if not video_id:
                continue

            canonical = f"https://www.redtube.com/{video_id}"

            if video_id not in seen:
                urls.append(canonical)
                seen.add(video_id)

    return urls


def blocked_reason(url: str) -> str | None:
    parsed = urllib.parse.urlparse(url)
    return matched_blocked_term(parsed.path)


def write_urls(path: Path, urls: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        "\n".join(urls) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def main() -> int:
    args = parse_args()

    try:
        validate_policy()
        validate_positive_args(args)

        output_path = Path(args.output).expanduser()
        exclude_path = (
            Path(args.exclude_ids).expanduser()
            if args.exclude_ids
            else None
        )
        excluded_ids = read_excluded_ids(exclude_path)

        accepted_urls: list[str] = []
        seen_ids: set[str] = set(excluded_ids)

        total_found = 0
        total_blocked = 0
        total_duplicate = 0
        pages_processed = 0
        empty_pages_in_row = 0

        print(f"QUERY={args.query}")
        print(f"TARGET={args.target}")
        print(f"EXCLUDED_IDS={len(excluded_ids)}")
        print(f"START_PAGE={args.start_page}")
        print(f"MAX_PAGES={args.max_pages}")
        print(f"POLICY_VERSION={POLICY_VERSION}")
        print(f"POLICY_TERMS={blocked_term_count()}")

        for page in range(
            args.start_page,
            args.start_page + args.max_pages,
        ):
            if len(accepted_urls) >= args.target:
                break

            document = fetch_html(
                build_search_url(args.query, page),
                timeout=args.timeout,
                retries=args.retries,
            )

            page_urls = extract_watch_urls(document)
            pages_processed += 1
            total_found += len(page_urls)

            if not page_urls:
                empty_pages_in_row += 1
            else:
                empty_pages_in_row = 0

            page_added = 0
            page_blocked = 0
            page_duplicate = 0

            for url in page_urls:
                video_id = extract_video_id(url)
                if not video_id:
                    continue

                if video_id in seen_ids:
                    total_duplicate += 1
                    page_duplicate += 1
                    continue

                reason = blocked_reason(url)
                if reason is not None:
                    total_blocked += 1
                    page_blocked += 1
                    seen_ids.add(video_id)
                    continue

                accepted_urls.append(url)
                seen_ids.add(video_id)
                page_added += 1

                if len(accepted_urls) >= args.target:
                    break

            print(
                f"PAGE={page} "
                f"FOUND={len(page_urls)} "
                f"ADDED={page_added} "
                f"BLOCKED={page_blocked} "
                f"DUPLICATE={page_duplicate} "
                f"TOTAL={len(accepted_urls)}"
            )

            if empty_pages_in_row >= 3:
                print("STOP_REASON=THREE_CONSECUTIVE_EMPTY_PAGES")
                break

            if len(accepted_urls) < args.target and args.delay > 0:
                time.sleep(args.delay)

        if not accepted_urls:
            raise CollectorError("No usable candidate URLs were collected.")

        write_urls(output_path, accepted_urls)

        print()
        print(f"OUTPUT={output_path.resolve()}")
        print(f"URLS_COLLECTED={len(accepted_urls)}")
        print(f"PAGES_PROCESSED={pages_processed}")
        print(f"TOTAL_FOUND={total_found}")
        print(f"TOTAL_BLOCKED={total_blocked}")
        print(f"TOTAL_DUPLICATE={total_duplicate}")

        if len(accepted_urls) >= args.target:
            print("COLLECT_STATUS=PASS")
            return 0

        print(
            "COLLECT_STATUS=PARTIAL: "
            f"requested={args.target} collected={len(accepted_urls)}",
            file=sys.stderr,
        )
        return 2

    except CollectorError as exc:
        print(f"COLLECT_STATUS=FAIL: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
