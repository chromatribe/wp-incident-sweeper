#!/bin/bash
set -e

DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$DIR"

echo "=== Building WP Incident Sweeper Plugin ZIP ==="

rm -f wp-incident-sweeper.zip

# Create zip with wp-incident-sweeper as root directory
zip -r wp-incident-sweeper.zip wp-incident-sweeper -x "*.DS_Store" "*__MACOSX*"

echo "=== Build Complete ==="
ls -lh wp-incident-sweeper.zip
