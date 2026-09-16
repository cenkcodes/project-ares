"""Provider-independent, reject-only validation of source video titles."""

from __future__ import annotations

import html
import re
import unicodedata


class TitleQualityError(ValueError):
    """Stable reason code suitable for the existing builder reject reports."""

    def __init__(self, reason_code: str) -> None:
        self.reason_code = reason_code
        super().__init__(reason_code)


_NUMERIC_ENTITY = re.compile(r"&#(?:[xX]([0-9a-fA-F]+)|([0-9]+));?")


def _unsafe_codepoint(codepoint: int) -> str | None:
    if codepoint == 0xFFFD:
        return "TITLE_QUALITY_REPLACEMENT_CHAR"
    if 0xD800 <= codepoint <= 0xDFFF:
        return "TITLE_QUALITY_SURROGATE"
    if 0x80 <= codepoint <= 0x9F:
        return "TITLE_QUALITY_C1_CONTROL"
    if codepoint == 0x7F or (codepoint < 0x20 and codepoint not in (9, 10, 13)):
        return "TITLE_QUALITY_ASCII_CONTROL"
    return None


def _validate_characters(title: str) -> None:
    for character in title:
        reason = _unsafe_codepoint(ord(character))
        if reason:
            raise TitleQualityError(reason)


def prepare_source_title(
    raw_title: str, *, decode_html_entities: bool = True
) -> str:
    """Reject corruption before any entity/whitespace conversion can hide it.

    No encoding repair, Unicode normalization, transliteration or language
    heuristic is applied. Entity decoding is a single, optional operation.
    """
    if not isinstance(raw_title, str):
        raise TitleQualityError("TITLE_QUALITY_NOT_STRING")

    _validate_characters(raw_title)
    for match in _NUMERIC_ENTITY.finditer(raw_title):
        hexadecimal = match.group(1) is not None
        digits = (match.group(1) if hexadecimal else match.group(2)).lstrip("0") or "0"
        # Bound integer conversion, including arbitrarily long numeric entities.
        if len(digits) > (6 if hexadecimal else 7):
            raise TitleQualityError("TITLE_QUALITY_DANGEROUS_ENTITY")
        codepoint = int(digits, 16 if hexadecimal else 10)
        if codepoint > 0x10FFFF or _unsafe_codepoint(codepoint):
            raise TitleQualityError("TITLE_QUALITY_DANGEROUS_ENTITY")

    title = html.unescape(raw_title) if decode_html_entities else raw_title
    _validate_characters(title)
    title = re.sub(r"\s+", " ", title).strip()
    if not title:
        raise TitleQualityError("TITLE_QUALITY_EMPTY")
    # Formatting and combining marks alone are not a visible title. Preserve
    # ZWJ/ZWNJ and variation selectors when visible text/emoji accompanies them.
    if not any(unicodedata.category(char)[0] in "LNPS" for char in title):
        raise TitleQualityError("TITLE_QUALITY_INVISIBLE_ONLY")
    return title
