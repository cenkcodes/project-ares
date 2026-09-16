#!/usr/bin/env python3
"""Shared source-independent content policy for Xurvexa video import tools."""

from __future__ import annotations

import html
import re
import unicodedata
import urllib.parse

POLICY_VERSION = "2026-08-29.3"

# These exclusions are intentionally shared by every source collector/builder.
# They are applied to URL/path text as an early pass and to title, description,
# and source-provided tags as the authoritative metadata pass.
BLOCKED_TERMS = frozenset({
    # Age / minor-risk vocabulary.
    "teen", "teens", "teenage", "teenager", "teenagers",
    "young", "youngster", "youngsters",
    "schoolgirl", "schoolgirls", "schoolboy", "schoolboys",
    "school", "student", "students",
    "barely legal", "barelylegal",
    "18yo", "18 yo", "18 y o", "18-year-old", "18 year old",
    "18yearold", "18yearsold", "18 year", "18 years",
    "child", "children", "kid", "kids", "kiddie",
    "preteen", "pre teen", "pre-teen", "underage", "minor", "minors", "csam",

    # Common non-English age/minor-risk equivalents seen in source taxonomies.
    "adolescent", "adolescente", "adolescents", "adolescentes",
    "menor", "menores", "mineur", "mineure", "mineurs", "mineures",
    "colegial", "colegiala", "colegialas", "colegiales",
    "escolar", "escolares", "estudiante", "estudiantes",
    "estudante", "estudantes", "etudiant", "etudiante", "etudiants", "etudiantes",

    # Xurvexa editorial exclusions requested for all sources.
    "gay", "gays", "gay clips", "gay dudes", "gay emo", "gay fuck",
    "gay hardcore", "gay pawn", "gay porn", "gay sex",
    "gayclips", "gaydudes", "gayemo", "gayfuck",
    "gayhardcore", "gaypawn", "gayporn", "gaysex",
    "homosexual", "homosexuals", "homosexuality",
    "trans", "transgender", "transgenders", "transgendered",
    "transsexual", "transsexuals",
    "trans woman", "trans women", "transwoman", "transwomen",
    "trans girl", "trans girls", "transgirl", "transgirls",
    "trans man", "trans men", "transman", "transmen",
    "transvestite", "transvestites",
    "shemale", "shemales", "tranny", "trannies",
    "ladyboy", "ladyboys", "femboy", "femboys",
    "tgirl", "tgirls", "t girl", "t girls",
    "mtf", "ftm", "futa", "futanari",

    # Source-specific compound aliases observed during XVideos taxonomy discovery.
    "big dick ts", "big tits ts", "blowing ts cock",
    "ts porn", "ts porno", "ts sex", "ts shemale",
})

# Family/role-play vocabulary such as step-mom, step-sister, step-brother,
# incest, father-daughter, mom-son and family-sex is intentionally NOT part of
# the global block list. This is an explicit Xurvexa editorial decision.

# Short aliases that are too broad for substring/phrase matching are blocked
# only when the entire normalized field/tag equals one of these values.
EXACT_ONLY_BLOCKED_TERMS = frozenset({"ts"})

# Compact age forms that survive normalization without spaces.
COMPACT_PATTERNS = (
    ("18-age-compact", re.compile(r"(?<![a-z0-9])18(?:yo|yrs?old|years?old)(?![a-z0-9])")),
)


def normalize_for_policy(value: str) -> str:
    value = html.unescape(value or "")
    value = urllib.parse.unquote(value)
    value = unicodedata.normalize("NFKD", value)
    value = value.encode("ascii", "ignore").decode("ascii")
    value = value.lower()
    value = re.sub(r"[_/|+\-]+", " ", value)
    value = re.sub(r"[^a-z0-9\s]+", " ", value)
    value = re.sub(r"\s+", " ", value)
    return value.strip()


_NORMALIZED_TERMS = tuple(
    sorted(
        {normalize_for_policy(term) for term in BLOCKED_TERMS if normalize_for_policy(term)},
        key=len,
        reverse=True,
    )
)
_NORMALIZED_EXACT_ONLY = frozenset(
    normalize_for_policy(term) for term in EXACT_ONLY_BLOCKED_TERMS
)


def blocked_term_count() -> int:
    return len(_NORMALIZED_TERMS) + len(_NORMALIZED_EXACT_ONLY) + len(COMPACT_PATTERNS)


def matched_blocked_term(value: str) -> str | None:
    normalized = normalize_for_policy(value)
    if not normalized:
        return None

    if normalized in _NORMALIZED_EXACT_ONLY:
        return normalized

    for term in _NORMALIZED_TERMS:
        pattern = r"(?<![a-z0-9])" + re.escape(term) + r"(?![a-z0-9])"
        if re.search(pattern, normalized):
            return term

    for label, pattern in COMPACT_PATTERNS:
        if pattern.search(normalized):
            return label

    return None


def validate_policy() -> None:
    if not _NORMALIZED_TERMS:
        raise RuntimeError("Global video import policy contains no blocked terms.")
    if "18yearsold" not in _NORMALIZED_TERMS:
        raise RuntimeError("Required age-risk alias 18yearsold is missing.")
    if "gayporn" not in _NORMALIZED_TERMS:
        raise RuntimeError("Required gay compound alias gayporn is missing.")
    if "big dick ts" not in _NORMALIZED_TERMS:
        raise RuntimeError("Required source-specific TS compound alias is missing.")

    intentionally_allowed = (
        "step mom",
        "stepmom",
        "step sister",
        "stepsister",
        "step brother",
        "stepbrother",
        "incest",
        "father daughter",
        "mom son",
        "family sex",
    )

    for value in intentionally_allowed:
        match = matched_blocked_term(value)
        if match is not None:
            raise RuntimeError(
                f"Editorially allowed family-roleplay term '{value}' "
                f"is unexpectedly blocked by '{match}'."
            )
