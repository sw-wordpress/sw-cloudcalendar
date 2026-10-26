#!/usr/bin/env bash
# Translation workflow (text domain "sw-cloudcalendar"), same procedure as sw-backupit.
#   bin/i18n.sh pot       regenerate languages/sw-cloudcalendar.pot from the source code
#   bin/i18n.sh update    pot + merge new/changed strings into every languages/sw-cloudcalendar-<locale>.po
#   bin/i18n.sh compile   compile every .po to .mo and the JSON catalog of the block editor script
#   bin/i18n.sh check     fail when the .pot is outdated or a .po lacks a translation (CI)
# Needs GNU gettext (xgettext, msgmerge, msgfmt, msgcat) on the DEVELOPMENT machine only.
# The admin SPA ships its own catalogs (apps/admin/src/i18n).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LANG_DIR="$ROOT/languages"
POT="$LANG_DIR/sw-cloudcalendar.pot"
LOCALES=(de_DE)
VERSION="$(grep -m1 "Version:" "$ROOT/sw-cloudcalendar.php" | awk '{print $NF}')"

make_pot() {
  local out="${1:-$POT}" php_pot js_pot extra_pot
  php_pot="$(mktemp)"; js_pot="$(mktemp)"; extra_pot="$(mktemp)"
  (cd "$ROOT" && { find sw-cloudcalendar.php uninstall.php src -name '*.php'; } | sort -u | \
    xargs xgettext --language=PHP --from-code=UTF-8 --add-comments=translators \
      --keyword=__:1 --keyword=_e:1 --keyword=esc_html__:1 --keyword=esc_attr__:1 --keyword=esc_html_e:1 \
      --keyword=_x:1,2c --keyword=_n:1,2 --keyword=_nx:1,2,4c \
      --package-name="sw-cloudcalendar" --package-version="$VERSION" --msgid-bugs-address="" \
      --copyright-holder="SW CloudCalendar contributors" --sort-by-file --no-wrap -o "$php_pot")
  (cd "$ROOT" && xgettext --language=JavaScript --from-code=UTF-8 --keyword=__:1 --keyword=_x:1,2c --keyword=_n:1,2 \
      --msgid-bugs-address="" --sort-by-file --no-wrap -o "$js_pot" blocks/calendar/index.js)
  php "$ROOT/bin/i18n-extra.php" "$extra_pot"
  msgcat --use-first --no-wrap --sort-by-file -o "$out" "$php_pot" "$js_pot" "$extra_pot"
  rm -f "$php_pot" "$js_pot" "$extra_pot"
  sed -i 's/^"POT-Creation-Date:.*$/"POT-Creation-Date: YEAR-MO-DA HO:MI+ZONE\\n"/; s/charset=CHARSET/charset=UTF-8/' "$out"
}

case "${1:-}" in
  pot) make_pot ;;
  update)
    make_pot
    for locale in "${LOCALES[@]}"; do
      po="$LANG_DIR/sw-cloudcalendar-$locale.po"
      if [ -f "$po" ]; then msgmerge --quiet --update --no-fuzzy-matching --backup=none --no-wrap "$po" "$POT"; fi
    done
    "$0" compile
    ;;
  compile)
    for po in "$LANG_DIR"/sw-cloudcalendar-*.po; do msgfmt --check-format -o "${po%.po}.mo" "$po"; done
    php "$ROOT/bin/i18n-json.php"
    ;;
  check)
    tmp="$(mktemp)"; make_pot "$tmp"
    if ! diff <(grep -E '^(msgid|msgctxt)' "$tmp") <(grep -E '^(msgid|msgctxt)' "$POT") >/dev/null; then
      echo "languages/sw-cloudcalendar.pot is outdated - run bin/i18n.sh update" >&2; exit 1
    fi
    for locale in "${LOCALES[@]}"; do
      po="$LANG_DIR/sw-cloudcalendar-$locale.po"
      [ -f "$po" ] || { echo "missing $po" >&2; exit 1; }
      msgfmt --check-format --statistics -o /dev/null "$po" 2> "$tmp.stat" || exit 1
      if grep -Eq "untranslated|fuzzy" "$tmp.stat"; then echo "$po is incomplete: $(cat "$tmp.stat")" >&2; exit 1; fi
      [ -f "${po%.po}.mo" ] || { echo "missing ${po%.po}.mo - run bin/i18n.sh compile" >&2; exit 1; }
    done
    echo "translations are complete and up to date"
    ;;
  *) echo "usage: $0 pot|update|compile|check"; exit 1 ;;
esac
