#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SRC="$ROOT_DIR/wordpress/plugin/cleopatra-rentals-chat-widget"
DIST_DIR="$ROOT_DIR/dist"
ZIP_PATH="$DIST_DIR/cleopatra-rentals-chat-widget.zip"

mkdir -p "$DIST_DIR"
rm -f "$ZIP_PATH"

(
  cd "$ROOT_DIR/wordpress/plugin"
  zip -r "$ZIP_PATH" "cleopatra-rentals-chat-widget" >/dev/null
)

echo "Created: $ZIP_PATH"
