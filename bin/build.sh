#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="plugin-risk-radar"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PARENT_DIR="$(dirname "$PLUGIN_DIR")"

# Read version from plugin header
VERSION=$(grep -m1 "^ \* Version:" "$PLUGIN_DIR/$PLUGIN_SLUG.php" | awk '{print $3}')

OUTPUT="$PARENT_DIR/${PLUGIN_SLUG}-${VERSION}.zip"

# Remove any existing zip for this version
rm -f "$OUTPUT"

cd "$PARENT_DIR"
zip -r "$OUTPUT" "$PLUGIN_SLUG/" \
  --exclude "$PLUGIN_SLUG/.git/*" \
  --exclude "$PLUGIN_SLUG/bin/*" \
  --exclude "$PLUGIN_SLUG/*.zip"

echo "Built: $OUTPUT"
