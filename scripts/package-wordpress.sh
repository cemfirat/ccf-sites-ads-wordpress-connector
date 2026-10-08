#!/usr/bin/env sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
BUILD_DIR="$ROOT_DIR/dist/wordpress-package"
PLUGIN_DIR="$BUILD_DIR/ccf-google-ads-site-connector"
ZIP_PATH="$ROOT_DIR/dist/harika-wordpress-connector.zip"
LEGACY_ZIP_PATH="$ROOT_DIR/dist/ccf-sites-ads-connector.zip"

rm -rf "$BUILD_DIR" "$ZIP_PATH" "$LEGACY_ZIP_PATH"
mkdir -p "$PLUGIN_DIR/assets" "$PLUGIN_DIR/includes"

cp "$ROOT_DIR/wordpress/harika-wordpress-connector.php" "$PLUGIN_DIR/ccf-google-ads-site-connector.php"
cp "$ROOT_DIR/wordpress/harika-connector-runtime.inc" "$PLUGIN_DIR/"
cp "$ROOT_DIR/wordpress/readme.txt" "$PLUGIN_DIR/"
cp "$ROOT_DIR/wordpress/README.md" "$PLUGIN_DIR/README.md"
cp "$ROOT_DIR/wordpress/assets/harika-tracking.js" "$PLUGIN_DIR/assets/"
cp "$ROOT_DIR/wordpress/includes/"*.php "$PLUGIN_DIR/includes/"

cd "$BUILD_DIR"
zip -qr "$ZIP_PATH" ccf-google-ads-site-connector
unzip -t "$ZIP_PATH"

for REQUIRED_PATH in \
  ccf-google-ads-site-connector/ccf-google-ads-site-connector.php \
  ccf-google-ads-site-connector/includes/class-harika-authors.php \
  ccf-google-ads-site-connector/assets/harika-tracking.js
do
  if ! unzip -Z1 "$ZIP_PATH" | grep -Fxq "$REQUIRED_PATH"; then
    echo "Missing required package file: $REQUIRED_PATH" >&2
    exit 1
  fi
done

for file in "$PLUGIN_DIR"/*.php "$PLUGIN_DIR"/*.inc "$PLUGIN_DIR"/includes/*.php; do
  php -l "$file"
done

cp "$ZIP_PATH" "$LEGACY_ZIP_PATH"
echo "$ZIP_PATH"
echo "$LEGACY_ZIP_PATH"
