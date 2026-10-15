#!/usr/bin/env python3
"""WP Incident Sweeper のバージョン文字列の横断同期を検証する（Qlypx 方式）。

正本: PROJECT.md の **Current Version:**
形式: MAJOR.YYYYMMDD.連番（例 2.20261015.1）
実行: python3 scripts/check_version_sync.py
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read_text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def canonical_version() -> str:
    text = read_text(ROOT / "PROJECT.md")
    match = re.search(r"\*\*Current Version:\*\*\s*`([^`]+)`", text)
    if not match:
        raise ValueError("PROJECT.md: **Current Version:** not found")
    return match.group(1)


def main() -> int:
    version = canonical_version()
    errors: list[str] = []

    # 1. WordPress プラグインヘッダ
    plugin_php = ROOT / "wp-incident-sweeper" / "wp-incident-sweeper.php"
    match = re.search(r"^\s*\*\s*Version:\s*(\S+)", read_text(plugin_php), re.MULTILINE)
    if not match or match.group(1) != version:
        found = match.group(1) if match else "not found"
        errors.append(f"plugin header: Version must be {version!r} (found {found!r})")

    # 2. WordPress readme.txt
    readme = ROOT / "wp-incident-sweeper" / "readme.txt"
    match = re.search(r"^Stable tag:\s*(\S+)", read_text(readme), re.MULTILINE)
    if not match or match.group(1) != version:
        found = match.group(1) if match else "not found"
        errors.append(f"readme.txt: Stable tag must be {version!r} (found {found!r})")

    # 3. spec/SPEC.md
    spec = ROOT / "spec" / "SPEC.md"
    if not re.search(r"\*\*バージョン\*\*:\s*`" + re.escape(version) + r"`", read_text(spec)):
        errors.append(f"spec/SPEC.md: **バージョン**: `{version}` not found")

    # 4. docs/CHANGELOG.md
    changelog = ROOT / "docs" / "CHANGELOG.md"
    if f"## [{version}]" not in read_text(changelog):
        errors.append(f"docs/CHANGELOG.md: missing section ## [{version}]")

    # 5. PROJECT.md §Change Log
    project = read_text(ROOT / "PROJECT.md")
    if f"### {version}" not in project:
        errors.append(f"PROJECT.md: missing Change Log heading ### {version}")

    # 6. README.md
    readme_md = read_text(ROOT / "README.md")
    if f"`{version}`" not in readme_md:
        errors.append(f"README.md: release version must include `{version}`")

    # 7. version.json
    version_json_path = ROOT / "version.json"
    if not version_json_path.is_file():
        errors.append("version.json: missing")
    else:
        try:
            payload = json.loads(version_json_path.read_text(encoding="utf-8"))
            if payload.get("version") != version:
                errors.append(
                    f"version.json: version must be {version!r} (found {payload.get('version')!r})"
                )
        except json.JSONDecodeError as exc:
            errors.append(f"version.json: invalid JSON ({exc})")

    if errors:
        print("Version sync check FAILED", file=sys.stderr)
        print(f"Expected version={version}", file=sys.stderr)
        for err in errors:
            print(f"  - {err}", file=sys.stderr)
        return 1

    print(f"OK: version={version}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
