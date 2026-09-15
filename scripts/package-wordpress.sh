#!/usr/bin/env sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
BUILD_DIR="$ROOT_DIR/dist/wordpress-package"
PLUGIN_DIR="$BUILD_DIR/ccf-google-ads-site-connector"
ZIP_PATH="$ROOT_DIR/dist/ccf-sites-ads-connector.zip"

rm -rf "$BUILD_DIR" "$ZIP_PATH"
mkdir -p "$PLUGIN_DIR/assets" "$PLUGIN_DIR/includes"

cp "$ROOT_DIR/wordpress/ccf-google-ads-site-connector.php" "$PLUGIN_DIR/"
cp "$ROOT_DIR/wordpress/ccf-site-connector-runtime.inc" "$PLUGIN_DIR/"
cp "$ROOT_DIR/wordpress/readme.txt" "$PLUGIN_DIR/"
cp "$ROOT_DIR/wordpress/README.md" "$PLUGIN_DIR/README.md"
cp "$ROOT_DIR/wordpress/assets/ccf-tracking.js" "$PLUGIN_DIR/assets/"
cp "$ROOT_DIR/wordpress/includes/class-ccf-sites-control.php" "$PLUGIN_DIR/includes/"
cp "$ROOT_DIR/wordpress/includes/class-ccf-sites-content-admin.php" "$PLUGIN_DIR/includes/"

cd "$BUILD_DIR"
zip -qr "$ZIP_PATH" ccf-google-ads-site-connector
unzip -t "$ZIP_PATH"

echo "$ZIP_PATH"
