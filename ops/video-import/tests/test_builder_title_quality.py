import contextlib
import importlib.util
import io
import json
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from video_title_quality import TitleQualityError


def load_builder(provider):
    name = "title_test_" + provider
    spec = importlib.util.spec_from_file_location(name, ROOT / (provider + "-csv-builder.py"))
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


BUILDERS = {name: load_builder(name) for name in ("eporner", "xvideos", "xnxx", "redtube")}


def document(title):
    # Double-quoted content intentionally permits apostrophes in a title.
    return '<meta property="og:title" content="' + title + '">'


class BuilderTitleTests(unittest.TestCase):
    def metadata_context(self, stack, provider, titles):
        builder = BUILDERS[provider]
        if provider == "eporner":
            def api(url, *args, **kwargs):
                from urllib.parse import parse_qs, urlsplit
                video_id = parse_qs(urlsplit(url).query)["id"][0]
                return {"id": video_id, "title": titles[video_id], "length_sec": 120,
                        "keywords": "amateur", "views": 99}
            stack.enter_context(patch.object(builder, "fetch_json", side_effect=api))
            stack.enter_context(patch.object(builder, "validate_embed_url", return_value="https://example.com/embed"))
            stack.enter_context(patch.object(builder, "extract_thumbnail", return_value="https://example.com/thumb.jpg"))
            stack.enter_context(patch.object(builder, "extract_tags", return_value=("amateur",)))
        else:
            stack.enter_context(patch.object(builder, "validate_" + provider + "_url", side_effect=lambda url: url))
            stack.enter_context(patch.object(builder, "extract_video_id", side_effect=lambda url: url.rsplit("/", 1)[-1]))
            stack.enter_context(patch.object(builder, "resolve_metadata_page",
                side_effect=lambda url, **kwargs: (url, document(titles[url.rsplit("/", 1)[-1]]))))
            stack.enter_context(patch.object(builder, "extract_duration", return_value=120))
            stack.enter_context(patch.object(builder, "extract_thumbnail", return_value="https://example.com/thumb.jpg"))
            stack.enter_context(patch.object(builder, "extract_tags", return_value=("amateur",)))

    def test_all_provider_metadata_paths_preserve_clean_titles_and_reject_corruption(self):
        for provider, builder in BUILDERS.items():
            for title in ("Café 日本語 Русский 👩‍💻", "A &amp; B", "A\u0085B", "A\ufffdB", "&#133;"):
                with self.subTest(provider=provider, title=ascii(title)), contextlib.ExitStack() as stack:
                    self.metadata_context(stack, provider, {"123": title})
                    key = "123" if provider == "eporner" else "https://example.com/123"
                    if title in ("A\u0085B", "A\ufffdB", "&#133;"):
                        with self.assertRaises(TitleQualityError):
                            builder.build_metadata(key, timeout=1, retries=1)
                    else:
                        metadata = builder.build_metadata(key, timeout=1, retries=1)
                        self.assertEqual(title.replace("&amp;", "&"), metadata.title)
                        row = builder.metadata_to_row(metadata, "amateur")
                        self.assertEqual(metadata.title, row["title"])
                        self.assertEqual(provider + "-123", row["slug"])
                        self.assertEqual(120, row["duration"])
                        self.assertEqual("amateur", row["category"])
                        self.assertEqual(builder.CANONICAL_HEADERS, list(row))
                        terms = builder.metadata_to_source_terms(metadata)
                        self.assertTrue(terms)
                        self.assertTrue(all(term["video_slug"] == row["slug"] for term in terms))
                        self.assertTrue(all(term["normalized_term"] == "amateur" for term in terms))

    def test_preferred_invalid_title_cannot_fall_back(self):
        for provider in ("xvideos", "xnxx", "redtube"):
            builder = BUILDERS[provider]
            suffix = '<title>Clean fallback</title><script>html5player.setVideoTitle("Clean fallback");</script>'
            for bad in ("A\u0085B", "&#133;", "\ufffd", "   "):
                with self.subTest(provider=provider, bad=ascii(bad)):
                    with self.assertRaises(TitleQualityError):
                        builder.extract_title(document(bad) + suffix)

    def test_missing_title_uses_existing_fallback(self):
        for provider in ("xvideos", "xnxx"):
            builder = BUILDERS[provider]
            self.assertEqual("Café", builder.extract_title('<script>html5player.setVideoTitle("Café");</script>'))
            with self.assertRaises(TitleQualityError):
                builder.extract_title('<script>html5player.setVideoTitle("A&#133;B");</script>')
        self.assertEqual("Café", BUILDERS["redtube"].extract_title("<title>Café</title>"))

    def test_redtube_jsonld_title_is_validated_before_unescape(self):
        builder = BUILDERS["redtube"]
        for title in ("Café", "A &amp; B", "A\u0085B", "&#133;", "\ufffd", {}):
            doc = '<script type="application/ld+json">' + json.dumps({"name": title}) + '</script><title>Fallback</title>'
            with self.subTest(title=ascii(title)):
                if title in ("Café", "A &amp; B"):
                    self.assertEqual(title.replace("&amp;", "&"), builder.extract_title(doc))
                else:
                    with self.assertRaises(TitleQualityError):
                        builder.extract_title(doc)

    def test_redtube_entity_quoted_json_and_literal_controls(self):
        builder = BUILDERS["redtube"]
        for title, accepted in (("Clean title", True), ("A &amp; B", True),
                                ("A&#133;B", False), ("A\x01B", False)):
            for quoted in (False, True):
                payload = '{"name":"' + title + '"}'
                if quoted:
                    payload = payload.replace('"', '&quot;')
                doc = '<script type="application/ld+json">' + payload + '</script><title>Fallback</title>'
                with self.subTest(title=ascii(title), quoted=quoted):
                    if accepted:
                        self.assertEqual(title.replace("&amp;", "&"), builder.extract_title(doc))
                    else:
                        with self.assertRaises(TitleQualityError):
                            builder.extract_title(doc)

    def test_non_title_helpers_retain_existing_normalization(self):
        for provider in ("xvideos", "xnxx", "redtube"):
            builder = BUILDERS[provider]
            self.assertEqual("A & B", builder.extract_meta('<meta property="og:description" content=" A &amp;  B ">', "og:description"))
            self.assertEqual("a-b", builder.normalize_source_term("A B"))

    def test_main_rejects_before_rows_and_preserves_v8_order(self):
        for provider, builder in BUILDERS.items():
            with self.subTest(provider=provider), contextlib.ExitStack() as stack:
                titles = {"123": "Clean Café", "124": "A\u0085B", "125": "school"}
                self.metadata_context(stack, provider, titles)
                ids = list(titles)
                inputs = ids if provider == "eporner" else ["https://example.com/" + value for value in ids]
                args = SimpleNamespace(input="input.txt", category="amateur", output=None, rejects=None,
                                       source_terms=None, max_accepted=0, timeout=1, retries=1, delay=0)
                stack.enter_context(patch.object(builder, "parse_args", return_value=args))
                stack.enter_context(patch.object(builder, "validate_args"))
                stack.enter_context(patch.object(builder, "read_ids" if provider == "eporner" else "read_urls", return_value=inputs))
                stack.enter_context(patch.object(builder, "default_paths",
                    return_value=(Path("canonical.csv"), Path("rejected.csv"), Path("terms.csv"))))
                writer = stack.enter_context(patch.object(builder, "write_csv"))
                policy = stack.enter_context(patch.object(builder, "metadata_policy_reason", wraps=builder.metadata_policy_reason))
                rows = stack.enter_context(patch.object(builder, "metadata_to_row", wraps=builder.metadata_to_row))
                terms = stack.enter_context(patch.object(builder, "metadata_to_source_terms", wraps=builder.metadata_to_source_terms))
                if provider == "redtube":
                    stack.enter_context(patch.object(builder, "stabilize_thumbnail", return_value=("https://example.com/thumb.jpg", False)))
                stack.enter_context(patch("urllib.request.urlopen", side_effect=AssertionError("Network forbidden")))
                with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                    self.assertEqual(0, builder.main())
                self.assertEqual(["Clean Café", "school"], [call.args[0].title for call in policy.call_args_list])
                self.assertEqual(1, rows.call_count)
                self.assertEqual(1, terms.call_count)
                outputs = {call.args[0].name: call.args[2] for call in writer.call_args_list}
                self.assertEqual(1, len(outputs["canonical.csv"]))
                self.assertEqual("Clean Café", outputs["canonical.csv"][0]["title"])
                self.assertTrue(all(row["video_slug"] == provider + "-123" for row in outputs["terms.csv"]))
                self.assertEqual(2, len(outputs["rejected.csv"]))
                self.assertIn("TITLE_QUALITY_C1_CONTROL", outputs["rejected.csv"][0]["reason"])


if __name__ == "__main__":
    unittest.main()
