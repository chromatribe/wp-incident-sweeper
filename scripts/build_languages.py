#!/usr/bin/env python3
"""WP Incident Sweeper 言語ファイル生成・コンパイルスクリプト

1. wp-incident-sweeper.php から翻訳対象文字列（wp-incident-sweeper ドメイン）を自動抽出
2. wp-incident-sweeper/languages/wp-incident-sweeper.pot を生成
3. wp-incident-sweeper/languages/wp-incident-sweeper-ja.po を生成
4. msgfmt で wp-incident-sweeper-ja.mo をコンパイル
"""

import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN_DIR = ROOT / "wp-incident-sweeper"
LANG_DIR = PLUGIN_DIR / "languages"
PHP_FILE = PLUGIN_DIR / "wp-incident-sweeper.php"


def extract_strings(php_path: Path) -> list[str]:
    text = php_path.read_text(encoding="utf-8")
    pattern = r"""(?:__|esc_html__|esc_html_e|_e)\(\s*(['"])(.*?)\1\s*,\s*['"]wp-incident-sweeper['"]"""
    matches = re.findall(pattern, text, re.DOTALL)
    
    unique_strings = []
    seen = set()
    for _, s in matches:
        # Unescape standard PHP escapes if present
        cleaned = s.replace(r"\'", "'").replace(r'\"', '"')
        if cleaned not in seen:
            seen.add(cleaned)
            unique_strings.append(cleaned)
    return unique_strings


def format_po_entry(msgid: str, msgstr: str = "") -> str:
    def escape_str(val: str) -> str:
        lines = val.split("\n")
        if len(lines) == 1:
            return '"' + lines[0].replace('\\', '\\\\').replace('"', '\\"') + '"'
        out = '""\n'
        for idx, line in enumerate(lines):
            suffix = "\\n" if idx < len(lines) - 1 else ""
            out += '    "' + line.replace('\\', '\\\\').replace('"', '\\"') + suffix + '"\n'
        return out.rstrip("\n")

    return f"msgid {escape_str(msgid)}\nmsgstr {escape_str(msgstr)}\n\n"


def main():
    LANG_DIR.mkdir(parents=True, exist_ok=True)
    strings = extract_strings(PHP_FILE)
    print(f"Extracted {len(strings)} translatable strings from PHP.")

    header = (
        'msgid ""\n'
        'msgstr ""\n'
        '"Project-Id-Version: WP Incident Sweeper 2.20261015.8\\n"\n'
        '"Report-Msgid-Bugs-To: https://chromatri.be\\n"\n'
        '"POT-Creation-Date: 2026-10-15 18:40+0900\\n"\n'
        '"PO-Revision-Date: 2026-10-15 18:40+0900\\n"\n'
        '"Last-Translator: CHROMATRIBE <info@chromatri.be>\\n"\n'
        '"Language-Team: Japanese\\n"\n'
        '"Language: ja\\n"\n'
        '"MIME-Version: 1.0\\n"\n'
        '"Content-Type: text/plain; charset=UTF-8\\n"\n'
        '"Content-Transfer-Encoding: 8bit\\n"\n'
        '"Plural-Forms: nplurals=1; plural=0;\\n"\n'
        '"X-Generator: Python build_languages.py\\n"\n\n'
    )

    pot_path = LANG_DIR / "wp-incident-sweeper.pot"
    po_path = LANG_DIR / "wp-incident-sweeper-ja.po"
    mo_path = LANG_DIR / "wp-incident-sweeper-ja.mo"

    # 1. POT ファイル（msgstr は空）
    with open(pot_path, "w", encoding="utf-8") as f:
        f.write(header)
        for s in strings:
            f.write(format_po_entry(s, ""))

    # 2. 日本語 PO ファイル（原文が日本語のため msgstr = msgid）
    with open(po_path, "w", encoding="utf-8") as f:
        f.write(header)
        for s in strings:
            f.write(format_po_entry(s, s))

    print(f"Written POT to: {pot_path}")
    print(f"Written PO  to: {po_path}")

    # 3. msgfmt で MO にコンパイル
    cmd = ["/opt/homebrew/bin/msgfmt", "-o", str(mo_path), str(po_path)]
    res = subprocess.run(cmd, capture_output=True, text=True)
    if res.returncode != 0:
        raise RuntimeError(f"msgfmt failed: {res.stderr}")

    print(f"Compiled MO to: {mo_path} ({mo_path.stat().st_size} bytes)")


if __name__ == "__main__":
    main()
