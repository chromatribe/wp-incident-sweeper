#!/bin/bash
set -e

DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$DIR"

echo "=== 1. Checking Version Sync ==="
python3 scripts/check_version_sync.py

echo "=== 2. Running Test Suite ==="
python3 scripts/test_sweeper.py

echo "=== 3. Building Distribution ZIP ==="
bash scripts/build_zip.sh

echo "=== 4. Updating main branch for public release ==="
CURRENT_BRANCH=$(git branch --show-current)
if [ "$CURRENT_BRANCH" != "develop" ]; then
    echo "Error: Please run this script from develop branch."
    exit 1
fi

git commit -am "chore: prepare release files" || true

# Switch to main and pull latest distribution files
git checkout main
git checkout develop -- wp-incident-sweeper.zip signatures.json README.md PROJECT.md AGENTS.md version.json docs spec
git commit -am "chore: release update to distribution files" || true
git push origin main

git checkout develop
echo "=== Public Release Complete! ==="
