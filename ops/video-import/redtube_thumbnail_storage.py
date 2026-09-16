#!/usr/bin/env python3
"""
RedTube thumbnail persistence helper for Project Ares.

RedTube can expose temporary signed pix-cdn77.rdtcdn.com thumbnail URLs.
Those URLs may later return HTTP 410. This helper refreshes an expiring
thumbnail from the public RedTube watch page, downloads it with the required
RedTube Referer, and stores a permanent copy in Xurvexa public storage.

Stable RedTube thumbnail URLs are returned unchanged.
"""

from __future__ import annotations

import html
import os
import re
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/152.0.0.0 Safari/537.36"
)

DEFAULT_STORAGE_ROOT = Path(
    os.environ.get(
        "XURVEXA_REDTUBE_THUMBNAIL_STORAGE_ROOT",
        (
            "/var/www/project-ares/backend/"
            "storage/app/public/provider-thumbnails"
        ),
    )
)

DEFAULT_PUBLIC_BASE = os.environ.get(
    "XURVEXA_REDTUBE_THUMBNAIL_PUBLIC_BASE",
    "https://xurvexa.com/storage/provider-thumbnails",
).rstrip("/")

EXPIRING_QUERY_KEYS = {
    "validto",
    "hdnea",
    "exp",
    "hash",
}

CONTENT_TYPE_EXTENSIONS = {
    "image/jpeg": "jpg",
    "image/jpg": "jpg",
    "image/png": "png",
    "image/webp": "webp",
    "image/avif": "avif",
    "image/gif": "gif",
}

MIN_IMAGE_BYTES = 1_024
MAX_IMAGE_BYTES = 15_000_000


class ThumbnailPersistenceError(RuntimeError):
    pass


def _payload_matches_extension(
    payload: bytes,
    extension: str,
) -> bool:
    if extension == "jpg":
        return payload.startswith(
            b"\xff\xd8\xff"
        )

    if extension == "png":
        return payload.startswith(
            b"\x89PNG\r\n\x1a\n"
        )

    if extension == "gif":
        return payload.startswith(
            (
                b"GIF87a",
                b"GIF89a",
            )
        )

    if extension == "webp":
        return (
            len(payload) >= 12
            and payload[:4] == b"RIFF"
            and payload[8:12] == b"WEBP"
        )

    if extension == "avif":
        header = payload[:64]

        return (
            len(header) >= 12
            and header[4:8] == b"ftyp"
            and (
                b"avif" in header
                or b"avis" in header
            )
        )

    return False


def _validate_image_payload(
    payload: bytes,
    extension: str,
) -> None:
    if len(payload) < MIN_IMAGE_BYTES:
        raise ThumbnailPersistenceError(
            "RedTube thumbnail payload is unexpectedly small."
        )

    if not _payload_matches_extension(
        payload,
        extension,
    ):
        raise ThumbnailPersistenceError(
            "RedTube thumbnail payload does not match its declared image type."
        )


def _allowed_redtube_image_host(
    hostname: str | None,
) -> bool:
    host = (
        hostname
        or ""
    ).lower()

    return (
        host == "rdtcdn.com"
        or host.endswith(
            ".rdtcdn.com"
        )
    )


def is_expiring_thumbnail(
    url: str,
) -> bool:
    candidate = url.strip()

    if not candidate.startswith(
        ("https://", "http://")
    ):
        return False

    parsed = urllib.parse.urlparse(
        candidate
    )

    if not _allowed_redtube_image_host(
        parsed.hostname
    ):
        return False

    query = urllib.parse.parse_qs(
        parsed.query,
        keep_blank_values=True,
    )

    query_keys = {
        key.lower()
        for key in query.keys()
    }

    return bool(
        query_keys
        & EXPIRING_QUERY_KEYS
    )


def _parse_html_attributes(
    tag: str,
) -> dict[str, str]:
    attributes: dict[str, str] = {}

    for match in re.finditer(
        r"([:\w-]+)\s*=\s*([\"'])(.*?)\2",
        tag,
        flags=re.IGNORECASE | re.DOTALL,
    ):
        attributes[
            match.group(1).lower()
        ] = html.unescape(
            match.group(3)
        ).strip()

    return attributes


def _extract_og_image(
    document: str,
) -> str:
    for tag in re.findall(
        r"<meta\b[^>]*>",
        document,
        flags=re.IGNORECASE | re.DOTALL,
    ):
        attributes = _parse_html_attributes(
            tag
        )

        marker = (
            attributes.get(
                "property",
                "",
            )
            or attributes.get(
                "name",
                "",
            )
        ).lower()

        if marker != "og:image":
            continue

        candidate = (
            attributes.get(
                "content",
                "",
            )
            .strip()
        )

        if candidate.startswith(
            ("https://", "http://")
        ):
            return candidate

    raise ThumbnailPersistenceError(
        "RedTube watch page did not expose a usable og:image."
    )


def _fetch_watch_page(
    video_id: str,
    timeout: int,
    retries: int,
) -> tuple[str, str]:
    watch_url = (
        "https://www.redtube.com/"
        + video_id
    )

    last_error: Exception | None = None

    for attempt in range(
        1,
        retries + 1,
    ):
        request = urllib.request.Request(
            watch_url,
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
                    raise ThumbnailPersistenceError(
                        "Unexpected RedTube watch-page HTTP status: "
                        + str(status)
                    )

                payload = response.read()

                if not payload:
                    raise ThumbnailPersistenceError(
                        "RedTube watch page is empty."
                    )

                return (
                    watch_url,
                    payload.decode(
                        "utf-8",
                        errors="replace",
                    ),
                )

        except (
            urllib.error.HTTPError,
            urllib.error.URLError,
            TimeoutError,
            ThumbnailPersistenceError,
        ) as exc:
            last_error = exc

            if attempt < retries:
                time.sleep(
                    min(
                        2.0 * attempt,
                        5.0,
                    )
                )

    raise ThumbnailPersistenceError(
        "RedTube watch-page refresh failed after "
        f"{retries} attempt(s): {last_error}"
    )


def _fetch_image(
    url: str,
    referer: str,
    timeout: int,
    retries: int,
) -> tuple[bytes, str]:
    parsed = urllib.parse.urlparse(
        url
    )

    if not _allowed_redtube_image_host(
        parsed.hostname
    ):
        raise ThumbnailPersistenceError(
            "Refusing RedTube thumbnail host outside rdtcdn.com."
        )

    last_error: Exception | None = None

    for attempt in range(
        1,
        retries + 1,
    ):
        request = urllib.request.Request(
            url,
            headers={
                "User-Agent": USER_AGENT,
                "Referer": referer,
                "Accept": (
                    "image/jpeg,image/webp,image/avif,"
                    "image/png,image/*;q=0.8,*/*;q=0.5"
                ),
                "Accept-Language": "en-US,en;q=0.9",
                "Cache-Control": "no-cache",
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
                    raise ThumbnailPersistenceError(
                        "Unexpected RedTube thumbnail HTTP status: "
                        + str(status)
                    )

                content_type = (
                    response.headers.get(
                        "Content-Type",
                        "",
                    )
                    .split(
                        ";",
                        1,
                    )[0]
                    .strip()
                    .lower()
                )

                extension = (
                    CONTENT_TYPE_EXTENSIONS.get(
                        content_type
                    )
                )

                if extension is None:
                    raise ThumbnailPersistenceError(
                        "Unsupported RedTube thumbnail content type: "
                        + (
                            content_type
                            or "(blank)"
                        )
                    )

                content_length = (
                    response.headers.get(
                        "Content-Length"
                    )
                )

                if (
                    content_length
                    and content_length.isdigit()
                    and int(content_length)
                    > MAX_IMAGE_BYTES
                ):
                    raise ThumbnailPersistenceError(
                        "RedTube thumbnail exceeds maximum allowed size."
                    )

                payload = response.read(
                    MAX_IMAGE_BYTES
                    + 1
                )

                if len(payload) > MAX_IMAGE_BYTES:
                    raise ThumbnailPersistenceError(
                        "RedTube thumbnail exceeds maximum allowed size."
                    )

                _validate_image_payload(
                    payload,
                    extension,
                )

                return (
                    payload,
                    extension,
                )

        except (
            urllib.error.HTTPError,
            urllib.error.URLError,
            TimeoutError,
            ThumbnailPersistenceError,
        ) as exc:
            last_error = exc

            if attempt < retries:
                time.sleep(
                    min(
                        2.0 * attempt,
                        5.0,
                    )
                )

    raise ThumbnailPersistenceError(
        "RedTube thumbnail download failed after "
        f"{retries} attempt(s): {last_error}"
    )


def _store_image(
    video_id: str,
    payload: bytes,
    extension: str,
    storage_root: Path,
    public_base: str,
) -> str:
    if not re.fullmatch(
        r"[0-9]+",
        video_id,
    ):
        raise ThumbnailPersistenceError(
            "Invalid RedTube video ID for thumbnail storage."
        )

    provider_directory = (
        storage_root
        / "redtube"
    )

    try:
        provider_directory.mkdir(
            parents=True,
            exist_ok=True,
        )
    except OSError as exc:
        raise ThumbnailPersistenceError(
            "Unable to create RedTube thumbnail storage directory: "
            + str(exc)
        ) from exc

    if not provider_directory.is_dir():
        raise ThumbnailPersistenceError(
            "RedTube thumbnail storage directory is unavailable."
        )

    filename = (
        "redtube-"
        + video_id
        + "."
        + extension
    )

    final_path = (
        provider_directory
        / filename
    )

    temporary_path = (
        provider_directory
        / (
            filename
            + ".tmp-"
            + str(os.getpid())
        )
    )

    try:
        with temporary_path.open(
            "wb",
        ) as handle:
            handle.write(
                payload
            )
            handle.flush()
            os.fsync(
                handle.fileno()
            )

        os.replace(
            temporary_path,
            final_path,
        )

        try:
            os.chmod(
                final_path,
                0o664,
            )
        except PermissionError:
            pass

    except OSError as exc:
        try:
            if temporary_path.exists():
                temporary_path.unlink()
        except OSError:
            pass

        raise ThumbnailPersistenceError(
            "Unable to persist RedTube thumbnail: "
            + str(exc)
        ) from exc

    for stale_extension in (
        "jpg",
        "png",
        "webp",
        "avif",
        "gif",
    ):
        stale_path = (
            provider_directory
            / (
                "redtube-"
                + video_id
                + "."
                + stale_extension
            )
        )

        if (
            stale_path != final_path
            and stale_path.is_file()
        ):
            try:
                stale_path.unlink()
            except OSError:
                pass

    return (
        public_base.rstrip("/")
        + "/redtube/"
        + filename
    )


def stabilize_thumbnail(
    video_id: str,
    thumbnail_url: str,
    timeout: int,
    retries: int,
    storage_root: Path | None = None,
    public_base: str | None = None,
) -> tuple[str, bool]:
    """
    Return (thumbnail_url, changed).

    Stable provider URLs are preserved.
    Expiring provider URLs are refreshed. If the refreshed URL is still
    expiring, the image is copied to Xurvexa public storage.
    """
    candidate = thumbnail_url.strip()

    if not candidate.startswith(
        ("https://", "http://")
    ):
        raise ThumbnailPersistenceError(
            "Thumbnail URL is missing or invalid."
        )

    if not is_expiring_thumbnail(
        candidate
    ):
        return candidate, False

    if not re.fullmatch(
        r"[0-9]+",
        video_id,
    ):
        raise ThumbnailPersistenceError(
            "Invalid RedTube video ID."
        )

    (
        watch_url,
        document,
    ) = _fetch_watch_page(
        video_id=video_id,
        timeout=timeout,
        retries=retries,
    )

    refreshed_thumbnail = _extract_og_image(
        document
    )

    parsed_refreshed = urllib.parse.urlparse(
        refreshed_thumbnail
    )

    if not _allowed_redtube_image_host(
        parsed_refreshed.hostname
    ):
        raise ThumbnailPersistenceError(
            "Refreshed RedTube thumbnail host is outside rdtcdn.com."
        )

    if not is_expiring_thumbnail(
        refreshed_thumbnail
    ):
        return refreshed_thumbnail, True

    effective_storage_root = (
        storage_root
        if storage_root is not None
        else DEFAULT_STORAGE_ROOT
    )

    effective_public_base = (
        public_base.rstrip("/")
        if public_base is not None
        else DEFAULT_PUBLIC_BASE
    )

    if not effective_public_base.startswith(
        ("https://", "http://")
    ):
        raise ThumbnailPersistenceError(
            "Xurvexa thumbnail public base URL is invalid."
        )

    (
        payload,
        extension,
    ) = _fetch_image(
        url=refreshed_thumbnail,
        referer=watch_url,
        timeout=timeout,
        retries=retries,
    )

    permanent_url = _store_image(
        video_id=video_id,
        payload=payload,
        extension=extension,
        storage_root=effective_storage_root,
        public_base=effective_public_base,
    )

    return permanent_url, True
