#!/usr/bin/env bash
# Builds an installable plugin zip: dist/flexo-booking-<version>.zip
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN="flexo-booking"
VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/$PLUGIN/$PLUGIN.php" | tr -d '[:space:]')"
OUT="$ROOT/dist/$PLUGIN-$VERSION.zip"

mkdir -p "$ROOT/dist"
rm -f "$OUT"
cd "$ROOT"
zip -rq "$OUT" "$PLUGIN" -x "*.DS_Store" -x "*/.git*" -x "*/node_modules/*" -x "$PLUGIN/ROADMAP.md" -x "$PLUGIN/IMPLEMENTATION_PLAN.md"
echo "Built $OUT"
