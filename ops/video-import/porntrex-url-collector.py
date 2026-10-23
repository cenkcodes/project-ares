#!/usr/bin/env python3
"""
PornTrex URL collector.

Collects public PornTrex watch-page URLs from provider category pages and
writes one canonical URL per line for the downstream PornTrex CSV builder.

Architecture contract:
    PornTrex category
        -> porntrex-url-collector.py
        -> porntrex-csv-builder.py
        -> shared video_import_policy.py
        -> canonical VideoImporter CSV
        -> Filament VideoImporter
        -> source-term importer
        -> integrity checks

Pagination:
    Logical page 0:
        Normal public category page.

    Logical pages 1+:
        PornTrex asynchronous category block endpoint using:
            mode=async
            function=get_block
            block_id=list_videos_common_videos_list_norm
            sort_by=post_date
            from=<logical page + 1>

The collector performs the shared policy's URL/path check as an early filter.
The downstream builder remains responsible for the authoritative metadata
policy pass against title, description, categories, and tags.
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

BASE_URL = "https://www.porntrex.com"

BLOCK_ID = "list_videos_common_videos_list_norm"

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/151.0.0.0 Safari/537.36"
)

VIDEO_LINK_PATTERN = re.compile(
    r'href=["\']'
    r'(?:https?://(?:www\.)?porntrex\.com)?'
    r'(/video/([0-9]+)/[^"\'?#<\s]+)'
    r'["\']',
    re.IGNORECASE,
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


class CollectorError(RuntimeError):
    pass


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Collect public PornTrex watch URLs from category pages "
            "for the canonical video-import pipeline."
        )
    )

    parser.add_argument(
        "--query",
        default="big-ass",
        help=(
            "PornTrex category slug or category-like value. "
            "Examples: big-ass, anal, bbw. Default: big-ass."
        ),
    )

    parser.add_argument(
        "--target",
        type=int,
        default=700,
        help="Number of candidate URLs to collect. Default: 700.",
    )

    parser.add_argument(
        "--output",
        required=True,
        help="Output text file; one canonical PornTrex URL per line.",
    )

    parser.add_argument(
        "--exclude-ids",
        help=(
            "Optional text file containing PornTrex IDs or PornTrex URLs "
            "already present in the target site. One item per line."
        ),
    )

    parser.add_argument(
        "--start-page",
        type=int,
        default=0,
        help=(
            "Logical first page index. "
            "0 is the normal category page; 1 is provider page 2. "
            "Default: 0."
        ),
    )

    parser.add_argument(
        "--max-pages",
        type=int,
        default=80,
        help="Maximum category pages to inspect. Default: 80.",
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
        help="HTTP attempts per page. Default: 3.",
    )

    parser.add_argument(
        "--delay",
        type=float,
        default=0.80,
        help="Delay between category pages in seconds. Default: 0.80.",
    )

    return parser.parse_args()


def normalize_category_slug(value: str) -> str:
    value = html.unescape(value or "")
    value = urllib.parse.unquote(value)
    value = value.strip().lower()

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


def validate_args(
    args: argparse.Namespace,
) -> str:
    if args.target <= 0:
        raise CollectorError(
            "--target must be greater than zero."
        )

    if args.start_page < 0:
        raise CollectorError(
            "--start-page cannot be negative."
        )

    if args.max_pages <= 0:
        raise CollectorError(
            "--max-pages must be greater than zero."
        )

    if args.timeout <= 0:
        raise CollectorError(
            "--timeout must be greater than zero."
        )

    if args.retries <= 0:
        raise CollectorError(
            "--retries must be greater than zero."
        )

    if args.delay < 0:
        raise CollectorError(
            "--delay cannot be negative."
        )

    category_slug = normalize_category_slug(
        args.query
    )

    if not category_slug:
        raise CollectorError(
            "--query resolved to an empty category slug."
        )

    query_block = matched_blocked_term(
        category_slug
    )

    if query_block:
        raise CollectorError(
            "Query is blocked by shared video-import policy: "
            + query_block
        )

    return category_slug


def extract_video_id(
    value: str,
) -> str | None:
    for pattern in VIDEO_ID_PATTERNS:
        match = pattern.search(value)

        if match:
            return match.group(1)

    stripped = value.strip()

    if re.fullmatch(
        r"[0-9]+",
        stripped,
    ):
        return stripped

    return None


def read_excluded_ids(
    path: Path | None,
) -> set[str]:
    if path is None:
        return set()

    if not path.is_file():
        raise CollectorError(
            f"Exclude file not found: {path}"
        )

    excluded: set[str] = set()

    for raw_line in path.read_text(
        encoding="utf-8-sig"
    ).splitlines():
        line = raw_line.strip()

        if (
            not line
            or line.startswith("#")
        ):
            continue

        video_id = extract_video_id(
            line
        )

        if video_id:
            excluded.add(
                video_id
            )

    return excluded


def category_url(
    category_slug: str,
) -> str:
    return (
        f"{BASE_URL}/categories/"
        f"{category_slug}/"
    )


def build_page_request(
    category_slug: str,
    logical_page: int,
) -> urllib.request.Request:
    base = category_url(
        category_slug
    )

    headers = {
        "User-Agent": USER_AGENT,
        "Accept": (
            "text/html,application/xhtml+xml,"
            "application/xml;q=0.9,*/*;q=0.8"
        ),
        "Accept-Language": "en-US,en;q=0.9",
        "Cache-Control": "no-cache",
        "Pragma": "no-cache",
    }

    if logical_page == 0:
        return urllib.request.Request(
            base,
            headers=headers,
        )

    provider_page = logical_page + 1

    params = urllib.parse.urlencode(
        {
            "mode": "async",
            "function": "get_block",
            "block_id": BLOCK_ID,
            "sort_by": "post_date",
            "from": provider_page,
        }
    )

    headers["Referer"] = base
    headers["X-Requested-With"] = (
        "XMLHttpRequest"
    )

    return urllib.request.Request(
        f"{base}?{params}",
        headers=headers,
    )


def fetch_html(
    request: urllib.request.Request,
    timeout: int,
    retries: int,
) -> str:
    last_error: Exception | None = None

    for attempt in range(
        1,
        retries + 1,
    ):
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
                    raise CollectorError(
                        "Unexpected HTTP status "
                        f"{status} for "
                        f"{request.full_url}"
                    )

                payload = response.read()

                if not payload:
                    raise CollectorError(
                        "Empty response body for "
                        f"{request.full_url}"
                    )

                return payload.decode(
                    "utf-8",
                    errors="replace",
                )

        except (
            urllib.error.URLError,
            urllib.error.HTTPError,
            TimeoutError,
            CollectorError,
        ) as exc:
            last_error = exc

            if attempt < retries:
                time.sleep(
                    min(
                        2.0 * attempt,
                        5.0,
                    )
                )

    raise CollectorError(
        "Page fetch failed after "
        f"{retries} attempt(s): "
        f"{last_error}"
    )


def extract_watch_urls(
    document: str,
) -> list[str]:
    urls: list[str] = []
    seen: set[str] = set()

    for match in VIDEO_LINK_PATTERN.finditer(
        document
    ):
        path = html.unescape(
            match.group(1)
        ).strip()

        video_id = match.group(2)

        if not video_id:
            continue

        canonical = urllib.parse.urljoin(
            BASE_URL + "/",
            path,
        )

        if video_id in seen:
            continue

        seen.add(
            video_id
        )

        urls.append(
            canonical
        )

    return urls


def blocked_reason(
    url: str,
) -> str | None:
    parsed = urllib.parse.urlparse(
        url
    )

    return matched_blocked_term(
        parsed.path
    )


def write_urls(
    path: Path,
    urls: list[str],
) -> None:
    path.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    body = "\n".join(
        urls
    ) + "\n"

    path.write_text(
        body,
        encoding="utf-8",
        newline="\n",
    )


def main() -> int:
    args = parse_args()

    try:
        validate_policy()

        category_slug = validate_args(
            args
        )

        output_path = Path(
            args.output
        ).expanduser()

        exclude_path = (
            Path(
                args.exclude_ids
            ).expanduser()
            if args.exclude_ids
            else None
        )

        excluded_ids = read_excluded_ids(
            exclude_path
        )

        accepted_urls: list[str] = []

        seen_ids: set[str] = set(
            excluded_ids
        )

        total_found = 0
        total_blocked = 0
        total_duplicate = 0
        pages_processed = 0
        empty_pages_in_row = 0

        print(
            f"QUERY={args.query}"
        )

        print(
            f"CATEGORY_SLUG={category_slug}"
        )

        print(
            f"CATEGORY_URL="
            f"{category_url(category_slug)}"
        )

        print(
            f"TARGET={args.target}"
        )

        print(
            f"EXCLUDED_IDS="
            f"{len(excluded_ids)}"
        )

        print(
            f"START_PAGE="
            f"{args.start_page}"
        )

        print(
            f"MAX_PAGES="
            f"{args.max_pages}"
        )

        print(
            f"POLICY_VERSION="
            f"{POLICY_VERSION}"
        )

        print(
            f"POLICY_TERMS="
            f"{blocked_term_count()}"
        )

        for logical_page in range(
            args.start_page,
            args.start_page
            + args.max_pages,
        ):
            if (
                len(accepted_urls)
                >= args.target
            ):
                break

            request = build_page_request(
                category_slug,
                logical_page,
            )

            document = fetch_html(
                request,
                timeout=args.timeout,
                retries=args.retries,
            )

            page_urls = extract_watch_urls(
                document
            )

            pages_processed += 1
            total_found += len(
                page_urls
            )

            if not page_urls:
                empty_pages_in_row += 1
            else:
                empty_pages_in_row = 0

            page_added = 0
            page_blocked = 0
            page_duplicate = 0

            for url in page_urls:
                video_id = extract_video_id(
                    url
                )

                if not video_id:
                    continue

                if video_id in seen_ids:
                    total_duplicate += 1
                    page_duplicate += 1
                    continue

                reason = blocked_reason(
                    url
                )

                if reason is not None:
                    total_blocked += 1
                    page_blocked += 1
                    seen_ids.add(
                        video_id
                    )
                    continue

                accepted_urls.append(
                    url
                )

                seen_ids.add(
                    video_id
                )

                page_added += 1

                if (
                    len(accepted_urls)
                    >= args.target
                ):
                    break

            provider_page = (
                1
                if logical_page == 0
                else logical_page + 1
            )

            print(
                f"PAGE={logical_page} "
                f"PROVIDER_PAGE={provider_page} "
                f"FOUND={len(page_urls)} "
                f"ADDED={page_added} "
                f"BLOCKED={page_blocked} "
                f"DUPLICATE={page_duplicate} "
                f"TOTAL={len(accepted_urls)}"
            )

            if (
                empty_pages_in_row
                >= 3
            ):
                print(
                    "STOP_REASON="
                    "THREE_CONSECUTIVE_EMPTY_PAGES"
                )
                break

            if (
                len(accepted_urls)
                < args.target
                and args.delay > 0
            ):
                time.sleep(
                    args.delay
                )

        if not accepted_urls:
            raise CollectorError(
                "No usable candidate URLs "
                "were collected."
            )

        write_urls(
            output_path,
            accepted_urls,
        )

        print()

        print(
            f"OUTPUT="
            f"{output_path.resolve()}"
        )

        print(
            f"URLS_COLLECTED="
            f"{len(accepted_urls)}"
        )

        print(
            f"PAGES_PROCESSED="
            f"{pages_processed}"
        )

        print(
            f"TOTAL_FOUND="
            f"{total_found}"
        )

        print(
            f"TOTAL_BLOCKED="
            f"{total_blocked}"
        )

        print(
            f"TOTAL_DUPLICATE="
            f"{total_duplicate}"
        )

        if (
            len(accepted_urls)
            >= args.target
        ):
            print(
                "COLLECT_STATUS=PASS"
            )
            return 0

        print(
            "COLLECT_STATUS=PARTIAL: "
            f"requested={args.target} "
            f"collected={len(accepted_urls)}",
            file=sys.stderr,
        )

        return 2

    except (
        CollectorError,
        RuntimeError,
    ) as exc:
        print(
            f"COLLECT_STATUS=FAIL: {exc}",
            file=sys.stderr,
        )

        return 1


if __name__ == "__main__":
    raise SystemExit(
        main()
    )