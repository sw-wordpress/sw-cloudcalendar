#!/usr/bin/env bash
# Builds an installable WordPress plugin ZIP: production Composer dependencies isolated with Mozart + admin bundle.
# Usage: bin/build-zip.sh [output-dir]   (needs composer, Docker and node/npm on the BUILD machine only)
# SWC_SKIP_ADMIN_BUILD=1 reuses assets/admin, but only when it is newer than every file in apps/admin/src.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
NAME=sw-cloudcalendar

if [ "${SWC_SKIP_ADMIN_BUILD:-0}" = "1" ] && [ -f "$ROOT/assets/admin/app.js" ] \
  && [ -z "$(find "$ROOT/apps/admin/src" "$ROOT/apps/admin/package-lock.json" -newer "$ROOT/assets/admin/app.js" -print -quit)" ]; then
  echo ">> reusing admin bundle (SWC_SKIP_ADMIN_BUILD=1, assets are up to date)"
else
  echo ">> building admin bundle"
  (cd "$ROOT/apps/admin" && npm ci && npm run build)
fi

echo ">> assembling plugin tree"
mkdir -p "$STAGE/$NAME"
# Untracked, not ignored files (local notes, experiments) are never shipped.
UNTRACKED="$STAGE/untracked.txt"
if git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  git -C "$ROOT" ls-files --others --exclude-standard | grep -v '^assets/admin/' | sed 's|^|/|' > "$UNTRACKED" || true
else
  : > "$UNTRACKED"
fi
rsync -a --exclude-from="$UNTRACKED" --exclude-from=- "$ROOT/" "$STAGE/$NAME/" <<'EXCLUDES'
.git
.gitignore
.editorconfig
.phpunit.cache
.phpstan.cache
.phpcs*.cache
/vendor
/vendor-prefixed
/.github
/node_modules
/apps
/tests
/dist
/build
/bin/test-db.sh
/docs/CODE-STYLE.md
/docs/composer-vendor-prefixed.md
phpunit.xml.dist
phpstan.neon.dist
phpcs*.xml.dist
PLAN.md
composer.lock
EXCLUDES

echo ">> installing production dependencies"
cp "$ROOT/composer.json" "$ROOT/composer.lock" "$STAGE/$NAME/"
(cd "$STAGE/$NAME" && composer validate --strict && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --no-scripts)
php "$ROOT/tests/bootstrap.php" "$STAGE/$NAME"

echo ">> isolating production dependencies with Mozart"
docker run --rm --user "$(id -u):$(id -g)" \
  -v "$STAGE/$NAME:/project" \
  coenjacobs/mozart:1.2.3 /mozart/bin/mozart compose
test -f "$STAGE/$NAME/vendor-prefixed/autoload.php"
# Mozart rewrites the dependency packages only; the plugin's own references are rewritten here.
php "$ROOT/bin/prefix-sources.php" "$STAGE/$NAME"

# Prove the plugin works without Composer's original tree before packaging.
rm -rf "$STAGE/$NAME/vendor" "$STAGE/$NAME/bin"
rm -f "$STAGE/$NAME/composer.json" "$STAGE/$NAME/composer.lock"
php "$ROOT/tests/bootstrap.php" "$STAGE/$NAME"

mkdir -p "$OUT"
VERSION="$(grep -m1 "Version:" "$ROOT/sw-cloudcalendar.php" | awk '{print $NF}')"
rm -f "$OUT/$NAME-$VERSION.zip"
(cd "$STAGE" && zip -qr "$OUT/$NAME-$VERSION.zip" "$NAME")
echo ">> $OUT/$NAME-$VERSION.zip"
