import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from video_title_quality import TitleQualityError, prepare_source_title


class TitleQualityTests(unittest.TestCase):
    def assert_reject(self, value, reason, **kwargs):
        with self.assertRaises(TitleQualityError) as caught:
            prepare_source_title(value, **kwargs)
        self.assertEqual(reason, caught.exception.reason_code)
        self.assertEqual(reason, str(caught.exception))

    def test_clean_unicode_is_preserved_and_deterministic(self):
        for title in ["Plain ASCII", "Café déjà vu", "日本語のタイトル", "Русский заголовок",
                      "日本語 Café Русский", "😀", "❤️", "👩‍💻", "a\u200cb", "a\u200bb",
                      "Ã©", "Gnd"]:
            with self.subTest(title=ascii(title)):
                self.assertEqual(title, prepare_source_title(title))
                self.assertEqual(title, prepare_source_title(prepare_source_title(title)))
                self.assertEqual(prepare_source_title(title), prepare_source_title(title))

    def test_entities_and_whitespace(self):
        self.assertEqual("A & B", prepare_source_title("A &amp; B"))
        self.assertEqual("a b c", prepare_source_title(" \ta\nb\r\n  c "))
        self.assertEqual("日本語", prepare_source_title("&#x65e5;&#26412;&#35486;"))
        self.assertEqual("a b", prepare_source_title("a&#9;&#10;&#13;b"))
        self.assertEqual("A &amp; B", prepare_source_title("A &amp; B", decode_html_entities=False))
        self.assertEqual("&amp;", prepare_source_title("&amp;amp;"))

    def test_all_forbidden_controls_are_rejected_before_normalization(self):
        for cp in list(range(32)) + list(range(127, 160)):
            if cp in (9, 10, 13):
                continue
            reason = "TITLE_QUALITY_C1_CONTROL" if 128 <= cp <= 159 else "TITLE_QUALITY_ASCII_CONTROL"
            with self.subTest(cp=cp):
                self.assert_reject("A" + chr(cp) + "B", reason)

    def test_surrogates_and_replacement_character(self):
        for cp in (0xD800, 0xDBFF, 0xDC00, 0xDFFF):
            self.assert_reject(chr(cp), "TITLE_QUALITY_SURROGATE")
        self.assert_reject("A\ufffdB", "TITLE_QUALITY_REPLACEMENT_CHAR")

    def test_dangerous_numeric_entities_before_html_remapping(self):
        for cp in [0, 1, 8, 11, 12, 31, 127, *range(128, 160), 0xFFFD, 0xD800, 0xDFFF, 0x110000]:
            for entity in (f"&#{cp};", f"&#x{cp:x};", f"&#X{cp:X}", f"&#000{cp};"):
                with self.subTest(entity=entity):
                    self.assert_reject("A" + entity + "Z", "TITLE_QUALITY_DANGEROUS_ENTITY")
        self.assert_reject("&#" + "9" * 5000 + ";", "TITLE_QUALITY_DANGEROUS_ENTITY")

    def test_no_encoding_repair(self):
        raw = "日本語".encode("utf-8").decode("latin-1")
        self.assert_reject(raw, "TITLE_QUALITY_C1_CONTROL")
        self.assert_reject("\u00c3\u0085", "TITLE_QUALITY_C1_CONTROL")
        self.assertEqual("Ã©", prepare_source_title("Ã©"))

    def test_empty_invisible_and_non_string(self):
        for value in ("", " \t\r\n", "&nbsp;"):
            self.assert_reject(value, "TITLE_QUALITY_EMPTY")
        for value in ("\u200b", "\u200c\u200d", "\ufe0f", "\ufeff", "\u0301"):
            self.assert_reject(value, "TITLE_QUALITY_INVISIBLE_ONLY")
        for value in (None, [], {}, 12, False):
            self.assert_reject(value, "TITLE_QUALITY_NOT_STRING")


if __name__ == "__main__":
    unittest.main()
