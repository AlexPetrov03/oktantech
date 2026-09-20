#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$ROOT_DIR/languages"
OUT_FILE="$OUT_DIR/speedy-shipping.pot"

mkdir -p "$OUT_DIR"

# Extract all WordPress i18n strings from PHP sources into a POT template.
# Keep defaults in Bulgarian in source code; translations are provided by PO/MO files.
find "$ROOT_DIR" -type f -name '*.php' \
  -not -path '*/.idea/*' \
  -not -path '*/languages/*' \
  -print0 | xargs -0 xgettext \
  --from-code=UTF-8 \
  --language=PHP \
  --keyword=__ \
  --keyword=_e \
  --keyword=_x:1,2c \
  --keyword=_n:1,2 \
  --keyword=_nx:1,2,4c \
  --keyword=esc_html__ \
  --keyword=esc_html_e \
  --keyword=esc_attr__ \
  --keyword=esc_attr_e \
  --package-name='Speedy Shipping WooCommerce Plugin' \
  --msgid-bugs-address='https://speedy.bg' \
  --output="$OUT_FILE"

echo "Generated: $OUT_FILE"